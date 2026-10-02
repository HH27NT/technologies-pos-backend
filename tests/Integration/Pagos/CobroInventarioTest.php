<?php

namespace Tests\Integration\Pagos;

use App\Domain\Pagos\Services\RegistrarPagoService;
use App\Models\Establecimiento;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Orden;
use App\Models\RecetaProducto;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M12/M08 · OrdenPagada dispara el descuento de inventario (P1), recorriendo recetas;
 * permite negativo (P2); libera la mesa por derivación (regla 11); auditoría financiera
 * dentro de la transacción; aislamiento entre tenants.
 */
class CobroInventarioTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('cobro');
        $this->enContextoDe($this->establecimiento->id);
        Auth::login($this->admin);
        $this->abrirCaja($this->admin);
    }

    private function pagar(Orden $orden): void
    {
        app(RegistrarPagoService::class)->registrar($orden, [
            'id_tipo_pago' => TipoPago::where('nombre', 'efectivo')->value('id'),
            'monto' => (float) $orden->total,
        ]);
    }

    public function test_cobrar_descuenta_inventario_recorriendo_recetas(): void
    {
        // Insumo con 10 de stock; producto que lo consume (2 por unidad).
        $insumo = Insumo::factory()->conStock(10)->create(['stock_minimo' => 0]);
        $producto = $this->crearProducto(precioVenta: 50);
        $producto->update(['controla_inventario' => true]);
        RecetaProducto::create(['id_producto' => $producto->id, 'id_insumo' => $insumo->id, 'cantidad' => 2]);

        $orden = $this->ordenAbiertaConProducto($producto, cantidad: 3); // consume 2×3 = 6

        $this->pagar($orden);

        // Se asienta un movimiento 'venta' y el stock baja 6 (10 → 4).
        $this->assertDatabaseHas('movimientos_inventario', [
            'id_insumo' => $insumo->id, 'id_orden' => $orden->id, 'tipo' => 'venta',
        ]);
        $this->assertEquals(4, (float) $insumo->fresh()->stock_actual);
    }

    public function test_venta_permite_stock_negativo(): void
    {
        // Stock 1, consumo 5: la venta NO se bloquea (P2).
        $insumo = Insumo::factory()->conStock(1)->create(['stock_minimo' => 0]);
        $producto = $this->crearProducto(precioVenta: 50);
        $producto->update(['controla_inventario' => true]);
        RecetaProducto::create(['id_producto' => $producto->id, 'id_insumo' => $insumo->id, 'cantidad' => 5]);

        $orden = $this->ordenAbiertaConProducto($producto, cantidad: 1);
        $this->pagar($orden);

        $this->assertEquals(-4, (float) $insumo->fresh()->stock_actual);
    }

    public function test_venta_en_negativo_devuelve_aviso_de_stock(): void
    {
        // Stock 1, consumo 5: la venta procede (P2) pero el resultado avisa cuál insumo quedó en negativo.
        $insumo = Insumo::factory()->conStock(1)->create(['nombre' => 'Limón', 'stock_minimo' => 0]);
        $producto = $this->crearProducto(precioVenta: 50);
        $producto->update(['controla_inventario' => true]);
        RecetaProducto::create(['id_producto' => $producto->id, 'id_insumo' => $insumo->id, 'cantidad' => 5]);

        $orden = $this->ordenAbiertaConProducto($producto, cantidad: 1);
        $resultado = app(RegistrarPagoService::class)->registrar($orden, [
            'id_tipo_pago' => TipoPago::where('nombre', 'efectivo')->value('id'),
            'monto' => (float) $orden->total,
        ]);

        $this->assertCount(1, $resultado['avisos_stock']);
        $this->assertSame($insumo->id, $resultado['avisos_stock'][0]['id_insumo']);
        $this->assertSame('Limón', $resultado['avisos_stock'][0]['insumo']);
        $this->assertEquals(-4, $resultado['avisos_stock'][0]['stock_resultante']);
    }

    public function test_venta_sin_dejar_negativo_no_trae_avisos(): void
    {
        $insumo = Insumo::factory()->conStock(10)->create(['stock_minimo' => 0]);
        $producto = $this->crearProducto(precioVenta: 50);
        $producto->update(['controla_inventario' => true]);
        RecetaProducto::create(['id_producto' => $producto->id, 'id_insumo' => $insumo->id, 'cantidad' => 2]);

        $orden = $this->ordenAbiertaConProducto($producto, cantidad: 1);
        $resultado = app(RegistrarPagoService::class)->registrar($orden, [
            'id_tipo_pago' => TipoPago::where('nombre', 'efectivo')->value('id'),
            'monto' => (float) $orden->total,
        ]);

        $this->assertSame([], $resultado['avisos_stock']);
    }

    public function test_producto_sin_receta_no_genera_movimiento(): void
    {
        // controla_inventario=true pero sin receta: no hay qué descontar (contrato S4).
        $producto = $this->crearProducto(precioVenta: 50);
        $producto->update(['controla_inventario' => true]);

        $orden = $this->ordenAbiertaConProducto($producto, cantidad: 2);
        $this->pagar($orden);

        $this->assertSame(0, MovimientoInventario::where('id_orden', $orden->id)->count());
    }

    public function test_cobro_libera_la_mesa_por_derivacion(): void
    {
        $mesa = $this->crearMesa(1);
        $producto = $this->crearProducto(precioVenta: 50);
        $orden = $this->ordenAbiertaConProducto($producto, cantidad: 1, idMesa: $mesa->id);

        $this->assertNotNull($mesa->ordenAbierta()->first()); // ocupada

        $this->pagar($orden);

        // Sin orden abierta, la mesa queda libre por derivación (sin flag persistido).
        $this->assertNull($mesa->fresh()->ordenAbierta()->first());
    }

    public function test_auditoria_de_orden_pagada(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);
        $this->pagar($orden);

        $this->assertDatabaseHas('auditoria', [
            'id_establecimiento' => $this->establecimiento->id,
            'accion' => 'orden.pagada', 'entidad' => 'ordenes', 'entidad_id' => $orden->id,
        ]);
    }
}
