<?php

namespace Tests\Integration\Autorizaciones;

use App\Models\Establecimiento;
use App\Models\Insumo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M14 · El flujo de dos niveles enlaza la operación ejecutada con su autorización,
 * audita solicitud y resolución, y queda aislado por tenant.
 */
class AutorizacionFlujoTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('autzi');
        $this->operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');

        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
    }

    public function test_entrada_aprobada_enlaza_movimiento_y_audita(): void
    {
        $insumo = Insumo::factory()->conStock(0)->create();

        Sanctum::actingAs($this->operador);
        $idAutorizacion = $this->postJson('/api/v1/autorizaciones', [
            'tipo' => 'entrada_stock',
            'id_insumo' => $insumo->id,
            'cantidad' => 50,
            'costo_unitario' => 12.5,
            'motivo' => 'Reposición',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('auditoria', ['accion' => 'autorizacion.solicitada', 'entidad_id' => $idAutorizacion]);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/autorizaciones/{$idAutorizacion}/aprobar")->assertOk();

        // El movimiento de inventario queda trazado a la autorización (id_autorizacion).
        $this->assertDatabaseHas('movimientos_inventario', [
            'id_insumo' => $insumo->id,
            'tipo' => 'entrada',
            'id_autorizacion' => $idAutorizacion,
        ]);
        $this->assertDatabaseHas('auditoria', ['accion' => 'autorizacion.aprobada', 'entidad_id' => $idAutorizacion]);
        $this->assertEqualsWithDelta(50, (float) $insumo->fresh()->stock_actual, 0.001);
    }

    public function test_aislamiento_entre_tenants(): void
    {
        // Solicitud creada en el tenant A.
        $orden = $this->ordenAbiertaConTotal(100);
        $item = $orden->detalles()->firstOrFail();

        Sanctum::actingAs($this->operador);
        $idAutorizacion = $this->postJson('/api/v1/autorizaciones', [
            'tipo' => 'cancelar_item',
            'id_orden' => $orden->id,
            'id_item' => $item->id,
            'motivo' => 'A',
        ])->json('data.id');

        // El admin de OTRO tenant no puede verla ni resolverla (TenantScope → 404).
        ['admin' => $adminB] = $this->nuevoTenant('otrob');
        Sanctum::actingAs($adminB);

        $this->patchJson("/api/v1/autorizaciones/{$idAutorizacion}/aprobar")->assertNotFound();
        $this->assertDatabaseHas('autorizaciones', ['id' => $idAutorizacion, 'estado' => 'pendiente']);
    }
}
