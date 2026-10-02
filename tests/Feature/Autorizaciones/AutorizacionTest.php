<?php

namespace Tests\Feature\Autorizaciones;

use App\Models\Establecimiento;
use App\Models\Insumo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M14 · Flujo de dos niveles por HTTP: el operador solicita, el admin resuelve. El
 * operador no aprueba ni posee el permiso directo de las operaciones sensibles.
 */
class AutorizacionTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('autzf');
        $this->operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');

        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
    }

    /** Crea una orden con un renglón y devuelve [orden, item]. */
    private function ordenConItem(): array
    {
        $orden = $this->ordenAbiertaConTotal(100);

        return [$orden, $orden->detalles()->firstOrFail()];
    }

    public function test_operador_solicita_y_admin_aprueba_cancelar_item(): void
    {
        [$orden, $item] = $this->ordenConItem();

        Sanctum::actingAs($this->operador);
        $solicitud = $this->postJson('/api/v1/autorizaciones', [
            'tipo' => 'cancelar_item',
            'id_orden' => $orden->id,
            'id_item' => $item->id,
            'motivo' => 'Cliente se retiró',
        ])->assertCreated()->assertJsonPath('data.estado', 'pendiente');

        $idAutorizacion = $solicitud->json('data.id');
        $this->assertDatabaseHas('autorizaciones', ['id' => $idAutorizacion, 'estado' => 'pendiente']);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/autorizaciones/{$idAutorizacion}/aprobar")
            ->assertOk()
            ->assertJsonPath('data.estado', 'aprobada');

        $this->assertDatabaseHas('detalle_orden', ['id' => $item->id, 'estado_item' => 'cancelado', 'id_autorizacion' => $idAutorizacion]);
    }

    public function test_admin_rechaza_sin_efectos(): void
    {
        [$orden, $item] = $this->ordenConItem();

        Sanctum::actingAs($this->operador);
        $idAutorizacion = $this->postJson('/api/v1/autorizaciones', [
            'tipo' => 'cancelar_item',
            'id_orden' => $orden->id,
            'id_item' => $item->id,
            'motivo' => 'Duda',
        ])->json('data.id');

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/autorizaciones/{$idAutorizacion}/rechazar", ['motivo' => 'No procede'])
            ->assertOk()
            ->assertJsonPath('data.estado', 'rechazada');

        // El ítem permanece activo: el rechazo no ejecuta nada.
        $this->assertDatabaseMissing('detalle_orden', ['id' => $item->id, 'estado_item' => 'cancelado']);
    }

    public function test_operador_no_aprueba(): void
    {
        [$orden, $item] = $this->ordenConItem();

        Sanctum::actingAs($this->operador);
        $idAutorizacion = $this->postJson('/api/v1/autorizaciones', [
            'tipo' => 'cancelar_item',
            'id_orden' => $orden->id,
            'id_item' => $item->id,
            'motivo' => 'Prueba',
        ])->json('data.id');

        // El operador no puede resolver su propia solicitud.
        $this->patchJson("/api/v1/autorizaciones/{$idAutorizacion}/aprobar")->assertForbidden();
    }

    public function test_operador_sin_bloque_de_override_recibe_422(): void
    {
        [$orden, $item] = $this->ordenConItem();
        // Insumo válido del tenant: así la validación del sujeto pasa y el bloqueo es la
        // ausencia del bloque de override (422), no la forma del sujeto.
        $insumo = Insumo::factory()->create();

        Sanctum::actingAs($this->operador);

        // El operador ya NO recibe 403 directo: al carecer del permiso, el endpoint exige
        // el bloque de override (login + contraseña de admin). Sin él → 422 de validación.
        $this->patchJson("/api/v1/ordenes/{$orden->id}/items/{$item->id}/cancelar", ['motivo' => 'x'])->assertStatus(422);
        $this->patchJson("/api/v1/ordenes/{$orden->id}/anular", ['motivo' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/movimientos', ['id_insumo' => $insumo->id, 'tipo' => 'entrada', 'cantidad' => 5, 'motivo' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/movimientos', ['id_insumo' => $insumo->id, 'tipo' => 'ajuste', 'cantidad' => 5, 'motivo' => 'x'])->assertStatus(422);
    }

    public function test_solicitud_resuelta_no_se_reaprueba(): void
    {
        [$orden, $item] = $this->ordenConItem();

        Sanctum::actingAs($this->operador);
        $idAutorizacion = $this->postJson('/api/v1/autorizaciones', [
            'tipo' => 'cancelar_item',
            'id_orden' => $orden->id,
            'id_item' => $item->id,
            'motivo' => 'Prueba',
        ])->json('data.id');

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/autorizaciones/{$idAutorizacion}/aprobar")->assertOk();

        // Segundo intento sobre una solicitud ya resuelta → 422.
        $this->patchJson("/api/v1/autorizaciones/{$idAutorizacion}/aprobar")->assertStatus(422);
    }

    public function test_bandeja_lista_pendientes_para_el_admin(): void
    {
        [$orden, $item] = $this->ordenConItem();

        Sanctum::actingAs($this->operador);
        $this->postJson('/api/v1/autorizaciones', [
            'tipo' => 'anular_orden',
            'id_orden' => $orden->id,
            'motivo' => 'Mesa abandonada',
        ])->assertCreated();

        // El operador no ve la bandeja del admin.
        $this->getJson('/api/v1/autorizaciones')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/autorizaciones?estado=pendiente')
            ->assertOk()
            ->assertJsonPath('data.0.tipo', 'anular_orden');
    }
}
