<?php

namespace Tests\Unit\Inventario;

use App\Domain\Inventario\Services\ReconciliarStockService;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class ReconciliarStockServiceTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Insumo $insumo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $est, 'admin' => $admin] = $this->nuevoTenant('rec');
        app(TenantContext::class)->set($est->id);

        $this->insumo = Insumo::factory()->create(['nombre' => 'Ron', 'stock_actual' => 999]);
    }

    public function test_recalcula_stock_actual_desde_el_ledger(): void
    {
        // Ledger: +30 (entrada) +5 (ajuste) -8 (merma) -2 (rotura) = 25.
        MovimientoInventario::factory()->create(['id_insumo' => $this->insumo->id, 'tipo' => 'entrada', 'cantidad' => 30]);
        MovimientoInventario::factory()->create(['id_insumo' => $this->insumo->id, 'tipo' => 'ajuste', 'cantidad' => 5]);
        MovimientoInventario::factory()->create(['id_insumo' => $this->insumo->id, 'tipo' => 'merma', 'cantidad' => 8]);
        MovimientoInventario::factory()->create(['id_insumo' => $this->insumo->id, 'tipo' => 'rotura', 'cantidad' => 2]);

        $reconciliado = app(ReconciliarStockService::class)->reconciliar($this->insumo);

        $this->assertEquals(25, $reconciliado);
        $this->assertEquals(25, (float) $this->insumo->fresh()->stock_actual);
    }
}
