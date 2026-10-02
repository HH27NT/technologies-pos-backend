<?php

namespace Tests\Feature\Catalogo;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class ImpresoraTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('imp');
        Sanctum::actingAs($this->admin);
    }

    public function test_admin_crea_impresora(): void
    {
        $this->postJson('/api/v1/impresoras', ['nombre' => 'Caja', 'tipo' => 'ticket'])
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'ticket');

        $this->assertDatabaseHas('impresoras', [
            'id_establecimiento' => $this->establecimiento->id, 'nombre' => 'Caja',
        ]);
    }

    public function test_tipo_invalido_es_rechazado(): void
    {
        $this->postJson('/api/v1/impresoras', ['nombre' => 'X', 'tipo' => 'laser'])
            ->assertStatus(422);
    }

    public function test_actualizar_y_desactivar_impresora(): void
    {
        $id = $this->postJson('/api/v1/impresoras', ['nombre' => 'Barra', 'tipo' => 'barra'])->json('data.id');

        $this->putJson("/api/v1/impresoras/{$id}", ['conexion' => '192.168.1.50'])
            ->assertOk()->assertJsonPath('data.conexion', '192.168.1.50');

        $this->patchJson("/api/v1/impresoras/{$id}/activar", ['activa' => false])
            ->assertOk()->assertJsonPath('data.activa', false);
    }

    public function test_operador_no_gestiona_impresoras(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        $this->postJson('/api/v1/impresoras', ['nombre' => 'X', 'tipo' => 'cocina'])->assertStatus(403);
    }
}
