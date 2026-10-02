<?php

namespace Tests\Feature\Inventario;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class ProveedorTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('prov');
    }

    public function test_admin_crea_lista_y_actualiza_proveedor(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/proveedores', ['nombre' => 'Distribuidora Norte', 'email' => 'ventas@norte.test'])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Distribuidora Norte')
            ->json('data.id');

        $this->getJson('/api/v1/proveedores')->assertOk()->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/proveedores/{$id}", ['nombre' => 'Distribuidora Sur'])
            ->assertOk()->assertJsonPath('data.nombre', 'Distribuidora Sur');
    }

    public function test_activar_alterna_el_estado(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/proveedores', ['nombre' => 'Prov X'])->json('data.id');

        $this->patchJson("/api/v1/proveedores/{$id}/activar", ['activo' => false])
            ->assertOk()->assertJsonPath('data.activo', false);
    }

    public function test_nombre_obligatorio(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/proveedores', [])->assertStatus(422);
    }

    public function test_operador_no_gestiona_proveedores(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        $this->getJson('/api/v1/proveedores')->assertStatus(403);
        $this->postJson('/api/v1/proveedores', ['nombre' => 'Y'])->assertStatus(403);
    }
}
