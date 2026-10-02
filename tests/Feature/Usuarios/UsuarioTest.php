<?php

namespace Tests\Feature\Usuarios;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class UsuarioTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('a');
    }

    public function test_admin_crea_operador_en_su_tenant(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/usuarios', [
            'nombre' => 'Luis Operador',
            'email' => 'luis@a.test',
            'password' => 'password123',
            'rol' => 'operador',
        ])->assertCreated();

        $this->assertDatabaseHas('usuarios', [
            'email' => 'luis@a.test',
            'id_establecimiento' => $this->establecimiento->id,
        ]);
    }

    public function test_email_unico_por_establecimiento(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/usuarios', [
            'nombre' => 'Duplicado',
            'email' => $this->admin->email,
            'password' => 'password123',
            'rol' => 'operador',
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_no_se_desactiva_al_ultimo_admin_activo(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/usuarios/{$this->admin->id}/activar", ['activo' => false])
            ->assertStatus(409);
    }

    public function test_password_minima_de_8_caracteres(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/usuarios', [
            'nombre' => 'Corta',
            'email' => 'corta@a.test',
            'password' => '123',
            'rol' => 'operador',
        ])->assertStatus(422);
    }

    public function test_operador_no_gestiona_usuarios(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        $this->getJson('/api/v1/usuarios')->assertStatus(403);
    }

    public function test_aislamiento_un_admin_no_ve_usuarios_de_otro_tenant(): void
    {
        ['admin' => $adminB] = $this->nuevoTenant('b');

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/v1/usuarios/{$adminB->id}")->assertStatus(404);
    }
}
