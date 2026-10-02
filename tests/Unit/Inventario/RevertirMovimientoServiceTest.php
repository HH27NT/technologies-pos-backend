<?php

namespace Tests\Unit\Inventario;

use App\Domain\Inventario\Services\RevertirMovimientoService;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M08 · Salvaguarda de reversa (P3): solo revierte movimientos de origen `venta`;
 * jamás entrada/ajuste/merma.
 */
class RevertirMovimientoServiceTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $est, 'admin' => $this->admin] = $this->nuevoTenant('rev');
        $this->enContextoDe($est->id);
        Auth::login($this->admin);
        $this->abrirCaja($this->admin);
    }

    public function test_solo_revierte_movimientos_de_venta(): void
    {
        $insumo = Insumo::factory()->conStock(2)->create();
        $orden = $this->ordenAbiertaConTotal(100);

        // Un movimiento de venta (−3) y uno de merma (−1), ambos de la orden.
        MovimientoInventario::factory()->create([
            'id_insumo' => $insumo->id, 'id_usuario' => $this->admin->id, 'id_orden' => $orden->id,
            'tipo' => 'venta', 'cantidad' => 3, 'stock_resultante' => 2,
        ]);
        MovimientoInventario::factory()->create([
            'id_insumo' => $insumo->id, 'id_usuario' => $this->admin->id, 'id_orden' => $orden->id,
            'tipo' => 'merma', 'cantidad' => 1, 'stock_resultante' => 1,
        ]);

        $revertidos = app(RevertirMovimientoService::class)->revertirVentasDe($orden);

        // Solo la venta se revierte: 1 movimiento compensatorio 'entrada'.
        $this->assertSame(1, $revertidos);
        $this->assertSame(1, MovimientoInventario::where('id_orden', $orden->id)
            ->where('tipo', 'entrada')->count());
        // El stock se incrementa solo por la cantidad de la venta (2 + 3 = 5).
        $this->assertEquals(5, (float) $insumo->fresh()->stock_actual);
    }

    public function test_orden_sin_ventas_no_revierte_nada(): void
    {
        $orden = $this->ordenAbiertaConTotal(50);

        $this->assertSame(0, app(RevertirMovimientoService::class)->revertirVentasDe($orden));
    }
}
