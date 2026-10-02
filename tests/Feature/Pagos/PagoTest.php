<?php

namespace Tests\Feature\Pagos;

use App\Models\Establecimiento;
use App\Models\Insumo;
use App\Models\RecetaProducto;
use App\Models\SesionCaja;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class PagoTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('pagof');
        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
    }

    private function tipoPago(string $nombre = 'efectivo'): int
    {
        return TipoPago::where('nombre', $nombre)->value('id');
    }

    public function test_cobro_simple_cierra_la_orden(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);

        $this->postJson("/api/v1/ordenes/{$orden->id}/pagos", ['id_tipo_pago' => $this->tipoPago(), 'monto' => 100])
            ->assertCreated()
            ->assertJsonPath('data.estado_orden', 'pagada')
            ->assertJsonPath('data.saldo', 0);

        $this->assertDatabaseHas('ordenes', ['id' => $orden->id, 'estado' => 'pagada']);
    }

    public function test_cobro_simple_sin_insumos_no_trae_avisos_de_stock(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);

        $this->postJson("/api/v1/ordenes/{$orden->id}/pagos", ['id_tipo_pago' => $this->tipoPago(), 'monto' => 100])
            ->assertCreated()
            ->assertJsonPath('data.avisos_stock', []);
    }

    /** P2: la venta nunca se bloquea por falta de stock, pero el cobro avisa qué insumo quedó en negativo. */
    public function test_cobro_avisa_insumos_que_quedan_en_negativo(): void
    {
        $insumo = Insumo::factory()->conStock(1)->create(['nombre' => 'Limón', 'stock_minimo' => 0]);
        $producto = $this->crearProducto(precioVenta: 50);
        $producto->update(['controla_inventario' => true]);
        RecetaProducto::create(['id_producto' => $producto->id, 'id_insumo' => $insumo->id, 'cantidad' => 5]);
        $orden = $this->ordenAbiertaConProducto($producto, cantidad: 1);

        $this->postJson("/api/v1/ordenes/{$orden->id}/pagos", ['id_tipo_pago' => $this->tipoPago(), 'monto' => 50])
            ->assertCreated()
            ->assertJsonPath('data.estado_orden', 'pagada')
            ->assertJsonPath('data.avisos_stock.0.id_insumo', $insumo->id)
            ->assertJsonPath('data.avisos_stock.0.insumo', 'Limón')
            ->assertJsonPath('data.avisos_stock.0.stock_resultante', -4);
    }

    public function test_cobro_dividido(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);

        $this->postJson("/api/v1/ordenes/{$orden->id}/pagos", ['id_tipo_pago' => $this->tipoPago(), 'monto' => 60])
            ->assertCreated()
            ->assertJsonPath('data.estado_orden', 'abierta')
            ->assertJsonPath('data.saldo', 40);

        $this->postJson("/api/v1/ordenes/{$orden->id}/pagos", ['id_tipo_pago' => $this->tipoPago(), 'monto' => 40])
            ->assertCreated()
            ->assertJsonPath('data.estado_orden', 'pagada');

        $this->assertDatabaseCount('pagos', 2);
    }

    public function test_no_cobrar_orden_ya_pagada_devuelve_422(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);
        $this->postJson("/api/v1/ordenes/{$orden->id}/pagos", ['id_tipo_pago' => $this->tipoPago(), 'monto' => 100])->assertCreated();

        // Segundo cobro sobre una orden ya pagada.
        $this->postJson("/api/v1/ordenes/{$orden->id}/pagos", ['id_tipo_pago' => $this->tipoPago(), 'monto' => 10])
            ->assertStatus(422);
    }

    public function test_sobrepago_en_tarjeta_devuelve_422(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);

        // Tarjeta no admite sobrepago.
        $this->postJson("/api/v1/ordenes/{$orden->id}/pagos", ['id_tipo_pago' => $this->tipoPago('tarjeta'), 'monto' => 150])
            ->assertStatus(422);
    }

    public function test_cambio_en_efectivo(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);

        $this->postJson("/api/v1/ordenes/{$orden->id}/pagos", ['id_tipo_pago' => $this->tipoPago('efectivo'), 'monto' => 150])
            ->assertCreated()
            ->assertJsonPath('data.cambio', 50)
            ->assertJsonPath('data.estado_orden', 'pagada');
    }

    public function test_saldo_endpoint(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);

        $this->getJson("/api/v1/ordenes/{$orden->id}/saldo")
            ->assertOk()
            ->assertJsonPath('data.total', 100)
            ->assertJsonPath('data.saldo', 100)
            ->assertJsonPath('data.pagada', false);

        $this->postJson("/api/v1/ordenes/{$orden->id}/pagos", ['id_tipo_pago' => $this->tipoPago(), 'monto' => 30])->assertCreated();

        $this->getJson("/api/v1/ordenes/{$orden->id}/saldo")
            ->assertJsonPath('data.pagado', 30)
            ->assertJsonPath('data.saldo', 70);
    }

    public function test_cobrar_sin_caja_devuelve_409(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);
        // Cerrar la caja deja la venta sin compuerta: el cobro requiere caja abierta.
        SesionCaja::where('estado', 'abierta')->update(['estado' => 'cerrada', 'cerrada_at' => now()]);

        $this->postJson("/api/v1/ordenes/{$orden->id}/pagos", ['id_tipo_pago' => $this->tipoPago(), 'monto' => 100])
            ->assertStatus(409);
    }
}
