<?php

namespace Tests\Integration\Endurecimiento;

use App\Domain\Autorizaciones\Services\ResolverAutorizacionService;
use App\Domain\Pagos\Services\RegistrarPagoService;
use App\Models\Autorizacion;
use App\Models\Establecimiento;
use App\Models\Orden;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * S12 · Cobertura de auditoría (§15/§542): CRUD de maestro, acción de negocio,
 * impersonación (P17) y reimpresión (P14) dejan su fila en `auditoria`; y una acción que
 * FALLA no deja ni efecto ni auditoría (atomicidad: la auditoría vive en la transacción
 * de su acción).
 */
class CoberturaAuditoriaTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('cobaud');
        $this->operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');

        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
    }

    private function pagar(Orden $orden, float $total = 100): void
    {
        $efectivo = TipoPago::where('nombre', 'efectivo')->value('id');
        app(RegistrarPagoService::class)->registrar($orden, ['id_tipo_pago' => $efectivo, 'monto' => $total]);
    }

    public function test_crud_de_maestro_se_audita(): void
    {
        $this->postJson('/api/v1/categorias', ['nombre' => 'Bebidas'])->assertCreated();

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'categoria.creada',
            'entidad' => 'categorias_producto',
            'id_establecimiento' => $this->establecimiento->id,
        ]);
    }

    public function test_accion_de_negocio_se_audita(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);
        $this->pagar($orden);

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'orden.pagada',
            'entidad' => 'ordenes',
            'entidad_id' => $orden->id,
        ]);
    }

    public function test_impersonacion_se_audita(): void
    {
        // P17: el super_admin accede a un tenant vía cabecera; queda marcado y auditado.
        $super = Usuario::withoutGlobalScopes()->where('email', 'super@pos.local')->firstOrFail();
        Sanctum::actingAs($super);

        $this->getJson('/api/v1/reportes/dashboard', ['X-Establecimiento-Id' => (string) $this->establecimiento->id])
            ->assertOk();

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'soporte.impersonacion',
            'entidad' => 'establecimientos',
            'entidad_id' => $this->establecimiento->id,
        ]);
    }

    public function test_reimpresion_se_audita(): void
    {
        // P14: la reimpresión no requiere autorización pero se audita.
        $orden = $this->ordenAbiertaConTotal(100);
        $this->pagar($orden);
        $idTicket = $this->postJson("/api/v1/ordenes/{$orden->id}/ticket")->json('data.id');

        $this->postJson("/api/v1/tickets/{$idTicket}/reimprimir")->assertOk();

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'ticket.reimpreso',
            'entidad' => 'tickets',
            'entidad_id' => $idTicket,
        ]);
    }

    public function test_una_aprobacion_que_falla_no_deja_auditoria(): void
    {
        // Solicitud pendiente para cancelar un ítem de una orden que luego se paga.
        $orden = $this->ordenAbiertaConTotal(100);
        $item = $orden->detalles()->first();

        $autorizacion = Autorizacion::create([
            'id_usuario_solicita' => $this->operador->id,
            'tipo' => 'cancelar_item',
            'entidad' => 'detalle_orden',
            'entidad_id' => $item->id,
            'estado' => 'pendiente',
            'motivo' => 'Cliente cambió de opinión',
            'datos' => ['id_orden' => $orden->id, 'id_item' => $item->id],
        ]);

        // La orden se paga: el ítem ya no es cancelable.
        $this->pagar($orden);

        // Aprobar ejecuta el destino (CancelarItem), que falla → toda la transacción revierte.
        try {
            app(ResolverAutorizacionService::class)->aprobar($autorizacion);
            $this->fail('Se esperaba una excepción de dominio al aprobar sobre una orden pagada.');
        } catch (\Throwable $e) {
            // Esperado: la orden dejó de ser modificable.
        }

        // Ni la aprobación ni su auditoría quedaron persistidas (§15).
        $this->assertDatabaseMissing('auditoria', [
            'accion' => 'autorizacion.aprobada',
            'entidad_id' => $autorizacion->id,
        ]);
        $this->assertDatabaseHas('autorizaciones', [
            'id' => $autorizacion->id,
            'estado' => 'pendiente',
        ]);
    }
}
