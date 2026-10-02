<?php

namespace Tests\Feature\Ordenes;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class DescuentoTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('desc');
        $this->operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        $this->enContextoDe($this->establecimiento->id);
        $this->abrirCaja($this->admin);
    }

    public function test_operador_aplica_descuento_sin_autorizacion(): void
    {
        // El cajero (OPERADOR) crea, agrega y descuenta sin aprobación (P10).
        Sanctum::actingAs($this->operador);
        $producto = $this->crearProducto(precioVenta: 100);

        $id = $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('barra')])->json('data.id');
        $this->postJson("/api/v1/ordenes/{$id}/items", ['id_producto' => $producto->id, 'cantidad' => 2])->assertCreated();

        $this->postJson("/api/v1/ordenes/{$id}/descuento", ['descuento' => 50])
            ->assertOk()
            ->assertJsonPath('data.descuento', '50.00')
            ->assertJsonPath('data.total', '150.00'); // 200 − 50, sin impuesto
    }

    public function test_descuento_mayor_al_subtotal_devuelve_422(): void
    {
        Sanctum::actingAs($this->admin);
        $producto = $this->crearProducto(precioVenta: 100);

        $id = $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('barra')])->json('data.id');
        $this->postJson("/api/v1/ordenes/{$id}/items", ['id_producto' => $producto->id, 'cantidad' => 1])->assertCreated();

        // subtotal 100; un descuento de 150 excede la base gravable.
        $this->postJson("/api/v1/ordenes/{$id}/descuento", ['descuento' => 150])->assertStatus(422);
    }

    public function test_impuesto_se_calcula_sobre_el_neto(): void
    {
        // aplica_impuesto=true, tasa 16%. subtotal 100, descuento 20 => base 80;
        // impuesto 12.80; total 92.80 (D1: impuesto sobre el neto).
        $this->activarImpuesto($this->establecimiento->id, 16);
        Sanctum::actingAs($this->admin);
        $producto = $this->crearProducto(precioVenta: 100);

        $id = $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('barra')])->json('data.id');
        $this->postJson("/api/v1/ordenes/{$id}/items", ['id_producto' => $producto->id, 'cantidad' => 1])->assertCreated();

        $this->postJson("/api/v1/ordenes/{$id}/descuento", ['descuento' => 20])
            ->assertOk()
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.impuesto', '12.80')
            ->assertJsonPath('data.total', '92.80');
    }
}
