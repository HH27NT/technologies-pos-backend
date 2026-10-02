<?php

namespace Tests\Unit\Inventario;

use App\Domain\Inventario\Events\MovimientoRegistrado;
use App\Domain\Inventario\Events\StockBajoDetectado;
use App\Domain\Inventario\Services\RegistrarMovimientoService;
use App\Models\Insumo;
use App\Support\Exceptions\StockInsuficienteException;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class RegistrarMovimientoServiceTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Insumo $insumo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $est, 'admin' => $admin] = $this->nuevoTenant('mov');
        app(TenantContext::class)->set($est->id);
        Auth::login($admin);

        $this->insumo = Insumo::factory()->create(['nombre' => 'Cerveza Pilsen']);
    }

    private function service(): RegistrarMovimientoService
    {
        return app(RegistrarMovimientoService::class);
    }

    public function test_entrada_suma_stock_y_es_append_only(): void
    {
        $this->service()->registrar(['id_insumo' => $this->insumo->id, 'tipo' => 'entrada', 'cantidad' => 10]);
        $this->service()->registrar(['id_insumo' => $this->insumo->id, 'tipo' => 'entrada', 'cantidad' => 5]);

        $this->assertEquals(15, (float) $this->insumo->fresh()->stock_actual);
        $this->assertSame(2, $this->insumo->movimientos()->count());
    }

    public function test_stock_resultante_se_registra_en_el_movimiento(): void
    {
        $mov = $this->service()->registrar(['id_insumo' => $this->insumo->id, 'tipo' => 'entrada', 'cantidad' => 7.5]);

        $this->assertEquals(7.5, (float) $mov->stock_resultante);
    }

    public function test_merma_resta_stock(): void
    {
        $this->service()->registrar(['id_insumo' => $this->insumo->id, 'tipo' => 'entrada', 'cantidad' => 20]);
        $this->service()->registrar(['id_insumo' => $this->insumo->id, 'tipo' => 'merma', 'cantidad' => 8, 'motivo' => 'rotura caja']);

        $this->assertEquals(12, (float) $this->insumo->fresh()->stock_actual);
    }

    public function test_salida_manual_mayor_al_stock_se_bloquea(): void
    {
        $this->service()->registrar(['id_insumo' => $this->insumo->id, 'tipo' => 'entrada', 'cantidad' => 3]);

        try {
            $this->service()->registrar(['id_insumo' => $this->insumo->id, 'tipo' => 'merma', 'cantidad' => 5, 'motivo' => 'x']);
            $this->fail('Se esperaba StockInsuficienteException.');
        } catch (StockInsuficienteException) {
            // D1: la salida manual no puede dejar el stock en negativo.
        }

        // Ni el stock ni el ledger cambian (la transacción se revierte).
        $this->assertEquals(3, (float) $this->insumo->fresh()->stock_actual);
        $this->assertSame(1, $this->insumo->movimientos()->count());
    }

    public function test_emite_evento_movimiento_registrado(): void
    {
        Event::fake([MovimientoRegistrado::class, StockBajoDetectado::class]);

        $mov = $this->service()->registrar(['id_insumo' => $this->insumo->id, 'tipo' => 'entrada', 'cantidad' => 10]);

        Event::assertDispatched(
            MovimientoRegistrado::class,
            fn (MovimientoRegistrado $e) => $e->movimiento->is($mov),
        );
    }

    public function test_emite_stock_bajo_cuando_cae_en_o_bajo_el_minimo(): void
    {
        $insumo = Insumo::factory()->create(['nombre' => 'Ginebra', 'stock_minimo' => 5]);
        $this->service()->registrar(['id_insumo' => $insumo->id, 'tipo' => 'entrada', 'cantidad' => 20]);

        Event::fake([StockBajoDetectado::class]);

        // Queda en 4 (<= mínimo 5): debe avisar.
        $this->service()->registrar(['id_insumo' => $insumo->id, 'tipo' => 'merma', 'cantidad' => 16, 'motivo' => 'derrame']);

        Event::assertDispatched(
            StockBajoDetectado::class,
            fn (StockBajoDetectado $e) => $e->insumo->is($insumo),
        );
    }

    public function test_no_emite_stock_bajo_si_queda_por_encima_del_minimo(): void
    {
        $insumo = Insumo::factory()->create(['nombre' => 'Vodka', 'stock_minimo' => 5]);

        Event::fake([StockBajoDetectado::class]);

        // Queda en 20 (> mínimo 5): no debe avisar.
        $this->service()->registrar(['id_insumo' => $insumo->id, 'tipo' => 'entrada', 'cantidad' => 20]);

        Event::assertNotDispatched(StockBajoDetectado::class);
    }
}
