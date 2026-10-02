<?php

namespace Tests\Feature\Ordenes;

use App\Models\Establecimiento;
use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('item');
        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
    }

    private function nuevaOrden(): int
    {
        return $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('barra')])->json('data.id');
    }

    public function test_agregar_item_recalcula_totales_y_congela_precio(): void
    {
        $producto = $this->crearProducto(precioVenta: 100);
        $id = $this->nuevaOrden();

        $this->postJson("/api/v1/ordenes/{$id}/items", ['id_producto' => $producto->id, 'cantidad' => 2])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.total', '200.00');

        // El precio se congeló en el renglón: cambiar el catálogo no altera la orden.
        $producto->update(['precio_venta' => 999]);
        $this->getJson("/api/v1/ordenes/{$id}")
            ->assertJsonPath('data.detalles.0.precio_unitario', '100.00')
            ->assertJsonPath('data.total', '200.00');
    }

    public function test_modificar_cantidad_de_item_no_enviado(): void
    {
        $producto = $this->crearProducto(precioVenta: 50);
        $id = $this->nuevaOrden();
        $itemId = $this->postJson("/api/v1/ordenes/{$id}/items", ['id_producto' => $producto->id, 'cantidad' => 1])
            ->json('data.detalles.0.id');

        $this->putJson("/api/v1/ordenes/{$id}/items/{$itemId}", ['cantidad' => 3])
            ->assertOk()
            ->assertJsonPath('data.total', '150.00');
    }

    public function test_no_modificar_item_enviado_devuelve_422(): void
    {
        $producto = $this->crearProducto(precioVenta: 50);
        $id = $this->nuevaOrden();
        $itemId = $this->postJson("/api/v1/ordenes/{$id}/items", ['id_producto' => $producto->id, 'cantidad' => 1])
            ->json('data.detalles.0.id');

        // Confirmar comanda marca el renglón como enviado.
        $this->postJson("/api/v1/ordenes/{$id}/comanda")->assertOk();

        $this->putJson("/api/v1/ordenes/{$id}/items/{$itemId}", ['cantidad' => 3])->assertStatus(422);
    }

    public function test_producto_no_disponible_devuelve_422(): void
    {
        $producto = $this->crearProducto(disponible: false);
        $id = $this->nuevaOrden();

        $this->postJson("/api/v1/ordenes/{$id}/items", ['id_producto' => $producto->id, 'cantidad' => 1])
            ->assertStatus(422);
    }

    public function test_no_agregar_item_a_orden_anulada_devuelve_422(): void
    {
        $producto = $this->crearProducto();
        $id = $this->nuevaOrden();
        $this->patchJson("/api/v1/ordenes/{$id}/anular")->assertOk();

        $this->postJson("/api/v1/ordenes/{$id}/items", ['id_producto' => $producto->id, 'cantidad' => 1])
            ->assertStatus(422);
    }

    public function test_no_agregar_item_a_orden_pagada_devuelve_422(): void
    {
        $producto = $this->crearProducto();
        $id = $this->nuevaOrden();
        Orden::whereKey($id)->update(['estado' => 'pagada', 'cerrada_at' => now()]);

        $this->postJson("/api/v1/ordenes/{$id}/items", ['id_producto' => $producto->id, 'cantidad' => 1])
            ->assertStatus(422);
    }
}
