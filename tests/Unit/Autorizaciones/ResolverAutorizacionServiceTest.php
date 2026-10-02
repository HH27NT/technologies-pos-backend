<?php

namespace Tests\Unit\Autorizaciones;

use App\Domain\Autorizaciones\Services\ResolverAutorizacionService;
use App\Domain\Autorizaciones\Services\SolicitarAutorizacionService;
use App\Domain\Ordenes\EstadoItem;
use App\Models\Establecimiento;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Usuario;
use App\Support\Exceptions\AutorizacionYaResueltaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M14 · Resolución de autorizaciones: idempotencia (una resuelta no cambia de estado),
 * la aprobación invoca el servicio destino y enlaza id_autorizacion, el rechazo no
 * produce efectos.
 */
class ResolverAutorizacionServiceTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('autz');
        $this->operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');

        $this->enContextoDe($this->establecimiento->id);
        Auth::login($this->admin);
        $this->abrirCaja($this->admin);
    }

    private function solicitar(): SolicitarAutorizacionService
    {
        return app(SolicitarAutorizacionService::class);
    }

    private function resolver(): ResolverAutorizacionService
    {
        return app(ResolverAutorizacionService::class);
    }

    public function test_aprobar_cancelar_item_ejecuta_y_enlaza(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);
        $item = $orden->detalles()->firstOrFail();

        // El operador solicita; el admin aprueba.
        Auth::login($this->operador);
        $autorizacion = $this->solicitar()->solicitar([
            'tipo' => 'cancelar_item',
            'id_orden' => $orden->id,
            'id_item' => $item->id,
            'motivo' => 'Cliente cambió de opinión',
        ]);

        Auth::login($this->admin);
        $this->resolver()->aprobar($autorizacion);

        $item->refresh();
        $this->assertSame(EstadoItem::Cancelado->value, $item->estado_item);
        $this->assertSame($autorizacion->id, $item->id_autorizacion);
        $this->assertSame('aprobada', $autorizacion->fresh()->estado);
    }

    public function test_aprobar_entrada_stock_ejecuta_y_enlaza_movimiento(): void
    {
        $insumo = Insumo::factory()->conStock(10)->create();

        Auth::login($this->operador);
        $autorizacion = $this->solicitar()->solicitar([
            'tipo' => 'entrada_stock',
            'id_insumo' => $insumo->id,
            'cantidad' => 25,
            'motivo' => 'Compra a proveedor',
        ]);

        Auth::login($this->admin);
        $this->resolver()->aprobar($autorizacion);

        $movimiento = MovimientoInventario::where('id_insumo', $insumo->id)->where('tipo', 'entrada')->firstOrFail();
        $this->assertSame($autorizacion->id, $movimiento->id_autorizacion);
        $this->assertEqualsWithDelta(35, (float) $insumo->fresh()->stock_actual, 0.001);
    }

    public function test_solicitud_resuelta_no_cambia_de_estado(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);
        $item = $orden->detalles()->firstOrFail();

        Auth::login($this->operador);
        $autorizacion = $this->solicitar()->solicitar([
            'tipo' => 'cancelar_item',
            'id_orden' => $orden->id,
            'id_item' => $item->id,
            'motivo' => 'Error de captura',
        ]);

        Auth::login($this->admin);
        $this->resolver()->aprobar($autorizacion);

        // Un segundo intento (rechazar lo ya aprobado) no cambia el estado.
        $this->expectException(AutorizacionYaResueltaException::class);
        $this->resolver()->rechazar($autorizacion->fresh());
    }

    public function test_rechazar_no_produce_efectos(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);
        $item = $orden->detalles()->firstOrFail();

        Auth::login($this->operador);
        $autorizacion = $this->solicitar()->solicitar([
            'tipo' => 'cancelar_item',
            'id_orden' => $orden->id,
            'id_item' => $item->id,
            'motivo' => 'No procede',
        ]);

        Auth::login($this->admin);
        $this->resolver()->rechazar($autorizacion, ['motivo' => 'Sin justificación válida']);

        $item->refresh();
        $this->assertNotSame(EstadoItem::Cancelado->value, $item->estado_item);
        $this->assertSame('rechazada', $autorizacion->fresh()->estado);
    }
}
