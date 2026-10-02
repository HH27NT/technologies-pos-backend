<?php

namespace Tests\Feature\Ordenes;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class ComandaTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('comanda');
        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
    }

    public function test_confirmar_comanda_marca_enviado_y_no_toca_inventario(): void
    {
        // Producto que SÍ controla inventario: aun así, la comanda no lo descuenta (P1).
        $producto = $this->crearProducto();
        $producto->update(['controla_inventario' => true]);

        $id = $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('barra')])->json('data.id');
        $this->postJson("/api/v1/ordenes/{$id}/items", ['id_producto' => $producto->id, 'cantidad' => 2])->assertCreated();

        $this->postJson("/api/v1/ordenes/{$id}/comanda")
            ->assertOk()
            ->assertJsonPath('data.detalles.0.enviado', true);

        // El descuento de inventario se dispara al cobrar (S8), no aquí.
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_comanda_es_incremental(): void
    {
        $producto = $this->crearProducto();
        $id = $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('barra')])->json('data.id');
        $this->postJson("/api/v1/ordenes/{$id}/items", ['id_producto' => $producto->id, 'cantidad' => 1])->assertCreated();
        $this->postJson("/api/v1/ordenes/{$id}/comanda")->assertOk();

        // Un ítem agregado después queda pendiente (enviado=false) hasta la próxima comanda.
        $this->postJson("/api/v1/ordenes/{$id}/items", ['id_producto' => $producto->id, 'cantidad' => 1])->assertCreated();

        $detalles = $this->getJson("/api/v1/ordenes/{$id}")->json('data.detalles');
        $enviados = array_column($detalles, 'enviado');
        $this->assertEqualsCanonicalizing([true, false], $enviados);
    }
}
