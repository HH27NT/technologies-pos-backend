<?php

namespace Tests\Feature\Ordenes;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class OrdenTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('orden');
        $this->enContextoDe($this->establecimiento->id);
    }

    public function test_crear_orden_de_mesa(): void
    {
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
        $mesa = $this->crearMesa(1);

        $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('mesa'), 'id_mesa' => $mesa->id])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'abierta')
            ->assertJsonPath('data.folio', '000001')
            ->assertJsonPath('data.id_mesa', $mesa->id);

        $this->assertDatabaseHas('ordenes', [
            'id_establecimiento' => $this->establecimiento->id, 'id_mesa' => $mesa->id, 'estado' => 'abierta',
        ]);
    }

    public function test_crear_orden_de_barra_sin_mesa(): void
    {
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);

        $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('barra')])
            ->assertCreated()
            ->assertJsonPath('data.id_mesa', null)
            ->assertJsonPath('data.estado', 'abierta');
    }

    public function test_crear_sin_caja_devuelve_409(): void
    {
        Sanctum::actingAs($this->admin);
        // No se abre caja: la compuerta EnsureCajaAbierta bloquea la venta (regla global 5).

        $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('barra')])
            ->assertStatus(409);
    }

    public function test_orden_de_mesa_sin_id_mesa_devuelve_422(): void
    {
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);

        $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('mesa')])
            ->assertStatus(422);
    }

    public function test_mesa_ocupada_devuelve_409(): void
    {
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
        $mesa = $this->crearMesa(2);

        $datos = ['id_tipo_orden' => $this->tipoOrdenId('mesa'), 'id_mesa' => $mesa->id];
        $this->postJson('/api/v1/ordenes', $datos)->assertCreated();
        // Segunda orden abierta en la misma mesa: una sola orden abierta por mesa.
        $this->postJson('/api/v1/ordenes', $datos)->assertStatus(409);
    }

    public function test_listar_y_ver_orden(): void
    {
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
        $id = $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('barra')])->json('data.id');

        $this->getJson('/api/v1/ordenes')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/ordenes/{$id}")->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_orden_expone_al_usuario_que_atiende(): void
    {
        // Atribución (mesero fase 1): la orden expone {id, nombre} de quien la abrió,
        // sin fugar email/username. Cubre store, index y show.
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);

        $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('barra')])
            ->assertCreated()
            ->assertJsonPath('data.usuario.id', $this->admin->id)
            ->assertJsonPath('data.usuario.nombre', $this->admin->nombre)
            ->assertJsonMissingPath('data.usuario.email');

        $id = $this->getJson('/api/v1/ordenes')
            ->assertOk()
            ->assertJsonPath('data.0.usuario.id', $this->admin->id)
            ->assertJsonPath('data.0.usuario.nombre', $this->admin->nombre)
            ->json('data.0.id');

        $this->getJson("/api/v1/ordenes/{$id}")
            ->assertOk()
            ->assertJsonPath('data.usuario.id', $this->admin->id)
            ->assertJsonPath('data.usuario.nombre', $this->admin->nombre);
    }

    public function test_orden_de_otro_tenant_devuelve_404(): void
    {
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
        $id = $this->postJson('/api/v1/ordenes', ['id_tipo_orden' => $this->tipoOrdenId('barra')])->json('data.id');

        ['admin' => $otro] = $this->nuevoTenant('orden2');
        Sanctum::actingAs($otro);
        $this->getJson("/api/v1/ordenes/{$id}")->assertStatus(404);
    }
}
