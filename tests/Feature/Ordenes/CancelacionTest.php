<?php

namespace Tests\Feature\Ordenes;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class CancelacionTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('canc');
        $this->operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        $this->enContextoDe($this->establecimiento->id);
        $this->abrirCaja($this->admin);
    }

    /** @return array{0:int,1:int} [ordenId, itemId] con un renglón de 2×100. */
    private function ordenConItem(): array
    {
        $producto = $this->crearProducto(precioVenta: 100);
        $id = $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('barra')])->json('data.id');
        $itemId = $this->postJson("/api/v1/ordenes/{$id}/items", ['id_producto' => $producto->id, 'cantidad' => 2])
            ->json('data.detalles.0.id');

        return [$id, $itemId];
    }

    public function test_admin_cancela_item_y_recalcula(): void
    {
        Sanctum::actingAs($this->admin);
        [$id, $itemId] = $this->ordenConItem();

        $this->patchJson("/api/v1/ordenes/{$id}/items/{$itemId}/cancelar", ['motivo' => 'error de captura'])
            ->assertOk()
            ->assertJsonPath('data.estado_item', 'cancelado');

        // El renglón cancelado no cuenta: el total vuelve a 0.
        $this->getJson("/api/v1/ordenes/{$id}")->assertJsonPath('data.total', '0.00');
        $this->assertDatabaseHas('detalle_orden', ['id' => $itemId, 'estado_item' => 'cancelado']);
    }

    public function test_admin_anula_orden(): void
    {
        Sanctum::actingAs($this->admin);
        [$id] = $this->ordenConItem();

        $this->patchJson("/api/v1/ordenes/{$id}/anular", ['motivo' => 'walkout'])
            ->assertOk()
            ->assertJsonPath('data.estado', 'anulada');

        $this->assertDatabaseHas('ordenes', ['id' => $id, 'estado' => 'anulada']);
    }

    public function test_operador_no_puede_cancelar_item_sin_override(): void
    {
        Sanctum::actingAs($this->admin);
        [$id, $itemId] = $this->ordenConItem();

        // El operador carece del permiso directo (S7); desde M14 el endpoint le exige el
        // bloque de override (login+contraseña de admin). Sin él → 422 de validación.
        Sanctum::actingAs($this->operador);
        $this->patchJson("/api/v1/ordenes/{$id}/items/{$itemId}/cancelar")->assertStatus(422);
    }

    public function test_operador_no_puede_anular_orden_sin_override(): void
    {
        Sanctum::actingAs($this->admin);
        [$id] = $this->ordenConItem();

        Sanctum::actingAs($this->operador);
        $this->patchJson("/api/v1/ordenes/{$id}/anular")->assertStatus(422);
    }
}
