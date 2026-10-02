<?php

namespace Tests\Feature\Autorizaciones;

use App\Domain\Autorizaciones\Services\GestionarPinService;
use App\Models\Autorizacion;
use App\Models\Establecimiento;
use App\Models\Insumo;
use App\Models\Usuario;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M14.1 · Override de autorización por PIN de 6 dígitos. El OPERADOR ejecuta la operación al
 * instante tecleando SOLO el PIN de un autorizador; queda registrada en `autorizaciones` con
 * estado=aprobada y metodo=override. Verifica resolución del autorizador, permiso, tenant,
 * bitácora de intentos fallidos, throttling, atomicidad y que el admin con permiso directo no
 * cambia (retrocompatible).
 */
class OverrideAutorizacionTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private const PIN_ADMIN = '482913';

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('ovr');
        $this->operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');

        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);

        $this->fijarPin($this->admin, self::PIN_ADMIN);
    }

    /** Fija el PIN de un usuario en su establecimiento (lo que hace `PUT /mi-pin`). */
    private function fijarPin(Usuario $usuario, string $pin): void
    {
        $tenant = app(TenantContext::class);
        $anterior = $tenant->id();

        $tenant->set($usuario->id_establecimiento);
        app(GestionarPinService::class)->fijar($usuario, $pin);
        $tenant->set($anterior);
    }

    private function ordenConItem(): array
    {
        $orden = $this->ordenAbiertaConTotal(100);

        return [$orden, $orden->detalles()->firstOrFail()];
    }

    /** Bloque de override: solo el PIN (+ lo que agregue cada caso). */
    private function override(array $extra = []): array
    {
        return array_merge(['autorizacion_pin' => self::PIN_ADMIN], $extra);
    }

    public function test_operador_override_cancela_item_al_instante(): void
    {
        [$orden, $item] = $this->ordenConItem();

        Sanctum::actingAs($this->operador);
        $this->patchJson("/api/v1/ordenes/{$orden->id}/items/{$item->id}/cancelar", $this->override([
            'motivo' => 'Cliente se retiró sin consumir',
        ]))->assertOk()->assertJsonPath('data.estado_item', 'cancelado');

        $autorizacion = $this->assertAutorizacionOverride('cancelar_item', $item->id);
        $this->assertDatabaseHas('detalle_orden', [
            'id' => $item->id,
            'estado_item' => 'cancelado',
            'id_autorizacion' => $autorizacion,
        ]);
    }

    public function test_operador_override_anula_orden_al_instante(): void
    {
        [$orden] = $this->ordenConItem();

        Sanctum::actingAs($this->operador);
        $this->patchJson("/api/v1/ordenes/{$orden->id}/anular", $this->override([
            'motivo' => 'Mesa abandonada',
        ]))->assertOk()->assertJsonPath('data.estado', 'anulada');

        $this->assertAutorizacionOverride('anular_orden', $orden->id);
        $this->assertDatabaseHas('ordenes', ['id' => $orden->id, 'estado' => 'anulada']);
    }

    public function test_operador_override_registra_entrada_de_stock(): void
    {
        $insumo = Insumo::factory()->create(['stock_actual' => 10]);

        Sanctum::actingAs($this->operador);
        $this->postJson('/api/v1/movimientos', $this->override([
            'id_insumo' => $insumo->id,
            'tipo' => 'entrada',
            'cantidad' => 5,
            'costo_unitario' => 3,
            'motivo' => 'Reposición urgente',
        ]))->assertCreated()->assertJsonPath('data.tipo', 'entrada');

        $autorizacion = $this->assertAutorizacionOverride('entrada_stock', $insumo->id);
        $this->assertDatabaseHas('movimientos_inventario', [
            'id_insumo' => $insumo->id,
            'tipo' => 'entrada',
            'id_autorizacion' => $autorizacion,
        ]);
        $this->assertSame(15.0, (float) $insumo->fresh()->stock_actual);
    }

    public function test_operador_override_registra_ajuste_de_stock(): void
    {
        $insumo = Insumo::factory()->create(['stock_actual' => 10]);

        Sanctum::actingAs($this->operador);
        $this->postJson('/api/v1/movimientos', $this->override([
            'id_insumo' => $insumo->id,
            'tipo' => 'ajuste',
            'cantidad' => 2,
            'motivo' => 'Conteo a la alza',
        ]))->assertCreated();

        $this->assertAutorizacionOverride('ajuste_stock', $insumo->id);
    }

    public function test_pin_incorrecto_devuelve_422_generico_y_registra_el_intento(): void
    {
        [$orden, $item] = $this->ordenConItem();

        Sanctum::actingAs($this->operador);
        $this->patchJson("/api/v1/ordenes/{$orden->id}/items/{$item->id}/cancelar", $this->override([
            'autorizacion_pin' => '999111',
            'motivo' => 'x',
            'terminal' => 'CAJA-1',
        ]))->assertStatus(422)->assertJsonPath('message', 'PIN de autorización inválido.');

        $this->assertDatabaseHas('autorizacion_intentos', [
            'id_establecimiento' => $this->establecimiento->id,
            'id_usuario_solicita' => $this->operador->id,
            'tipo' => 'cancelar_item',
            'resultado' => 'pin_invalido',
            'terminal' => 'CAJA-1',
        ]);
        $this->assertDatabaseMissing('autorizaciones', ['metodo' => 'override']);
        $this->assertDatabaseMissing('detalle_orden', ['id' => $item->id, 'estado_item' => 'cancelado']);
    }

    public function test_autorizador_sin_permiso_devuelve_403_y_registra_el_intento(): void
    {
        [$orden, $item] = $this->ordenConItem();
        // Otro operador con PIN configurado (p. ej. lo tenía como admin y perdió el rol):
        // el PIN resuelve, pero no puede autorizar cancelaciones.
        $otroOperador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        $this->fijarPin($otroOperador, '735284');

        Sanctum::actingAs($this->operador);
        $this->patchJson("/api/v1/ordenes/{$orden->id}/items/{$item->id}/cancelar", $this->override([
            'autorizacion_pin' => '735284',
            'motivo' => 'x',
        ]))->assertStatus(403)->assertJsonPath('message', 'Ese usuario no puede autorizar esta operación.');

        $this->assertDatabaseHas('autorizacion_intentos', [
            'id_usuario_solicita' => $this->operador->id,
            'tipo' => 'cancelar_item',
            'resultado' => 'sin_permiso',
        ]);
        $this->assertDatabaseMissing('autorizaciones', ['metodo' => 'override']);
    }

    public function test_pin_de_admin_de_otro_tenant_no_autoriza(): void
    {
        [$orden, $item] = $this->ordenConItem();
        ['admin' => $adminAjeno] = $this->nuevoTenant('ajeno');
        $this->fijarPin($adminAjeno, '605172');

        Sanctum::actingAs($this->operador);
        // El PIN existe y verifica, pero pertenece a otro establecimiento: el TenantScope lo
        // oculta → 422 genérico (no revela que ese PIN es de alguien).
        $this->patchJson("/api/v1/ordenes/{$orden->id}/items/{$item->id}/cancelar", $this->override([
            'autorizacion_pin' => '605172',
            'motivo' => 'x',
        ]))->assertStatus(422)->assertJsonPath('message', 'PIN de autorización inválido.');

        $this->assertDatabaseMissing('autorizaciones', ['metodo' => 'override']);
    }

    public function test_autorizador_inactivo_se_trata_como_pin_invalido(): void
    {
        [$orden, $item] = $this->ordenConItem();
        $this->admin->update(['activo' => false]);

        Sanctum::actingAs($this->operador);
        $this->patchJson("/api/v1/ordenes/{$orden->id}/items/{$item->id}/cancelar", $this->override(['motivo' => 'x']))
            ->assertStatus(422)->assertJsonPath('message', 'PIN de autorización inválido.');
    }

    public function test_override_sin_pin_falla_la_validacion(): void
    {
        [$orden, $item] = $this->ordenConItem();

        Sanctum::actingAs($this->operador);
        $this->patchJson("/api/v1/ordenes/{$orden->id}/items/{$item->id}/cancelar", ['motivo' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('autorizacion_pin');
    }

    public function test_throttle_bloquea_fuerza_bruta_del_pin(): void
    {
        [$orden, $item] = $this->ordenConItem();
        $url = "/api/v1/ordenes/{$orden->id}/items/{$item->id}/cancelar";

        Sanctum::actingAs($this->operador);
        // 5 intentos con PIN malo agotan el límite; el 6º queda bloqueado (429 + Retry-After).
        for ($i = 0; $i < 5; $i++) {
            $this->patchJson($url, $this->override(['autorizacion_pin' => '999111', 'motivo' => 'x']))
                ->assertStatus(422);
        }

        $this->patchJson($url, $this->override(['autorizacion_pin' => '999111', 'motivo' => 'x']))
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // Ni siquiera el PIN correcto pasa mientras dura el bloqueo.
        $this->patchJson($url, $this->override(['motivo' => 'x']))->assertStatus(429);
    }

    public function test_override_correcto_no_penaliza_por_throttle(): void
    {
        Sanctum::actingAs($this->operador);

        // Varios overrides correctos seguidos NO agotan el límite (se limpia en cada éxito).
        for ($i = 0; $i < 7; $i++) {
            [$orden, $item] = $this->ordenConItem();
            $this->patchJson("/api/v1/ordenes/{$orden->id}/items/{$item->id}/cancelar", $this->override(['motivo' => 'x']))
                ->assertOk();
        }
    }

    public function test_fallo_de_ejecucion_revierte_la_autorizacion(): void
    {
        [$orden, $item] = $this->ordenConItem();
        // La orden deja de ser modificable (anulada por el admin directo).
        $this->anularComoAdmin($orden->id);

        Sanctum::actingAs($this->operador);
        // El override verifica el PIN pero la ejecución falla (orden no modificable); toda la
        // transacción se revierte: no queda autorización huérfana.
        $this->patchJson("/api/v1/ordenes/{$orden->id}/items/{$item->id}/cancelar", $this->override(['motivo' => 'x']))
            ->assertStatus(422);

        $this->assertDatabaseMissing('autorizaciones', ['metodo' => 'override']);
    }

    public function test_admin_con_permiso_directo_no_requiere_pin(): void
    {
        [$orden, $item] = $this->ordenConItem();

        // El admin ejecuta como siempre, sin bloque de override y sin dejar autorización.
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/ordenes/{$orden->id}/items/{$item->id}/cancelar", ['motivo' => 'Ajuste'])
            ->assertOk();

        $this->assertDatabaseMissing('autorizaciones', ['entidad_id' => $item->id]);
        $this->assertDatabaseHas('detalle_orden', ['id' => $item->id, 'estado_item' => 'cancelado', 'id_autorizacion' => null]);
    }

    /** Anula la orden como admin (directo), para invalidar su estado. */
    private function anularComoAdmin(int $ordenId): void
    {
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/ordenes/{$ordenId}/anular", ['motivo' => 'setup'])->assertOk();
    }

    /** Asserts + devuelve el id de la autorización override registrada para el sujeto. */
    private function assertAutorizacionOverride(string $tipo, int $entidadId): int
    {
        $this->assertDatabaseHas('autorizaciones', [
            'tipo' => $tipo,
            'entidad_id' => $entidadId,
            'estado' => 'aprobada',
            'metodo' => 'override',
            'id_usuario_solicita' => $this->operador->id,
            'id_usuario_autoriza' => $this->admin->id,
        ]);

        return (int) Autorizacion::where('tipo', $tipo)
            ->where('entidad_id', $entidadId)
            ->where('metodo', 'override')
            ->value('id');
    }
}
