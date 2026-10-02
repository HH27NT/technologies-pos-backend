<?php

namespace Tests\Feature\Usuarios;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * Asignación de roles del modelo de 5 roles (M04) y salvaguarda anti-escalada:
 * el ADMIN asigna cualquier rol; el GERENTE gestiona personal pero NO puede crear,
 * asignar ni tocar el rol `admin`. Ver docs/MatrizRoles.md.
 */
class GestionRolesTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $gerente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('roles');
        $this->gerente = $this->crearUsuarioEnTenant($this->establecimiento->id, 'gerente');
    }

    private function payload(string $rol, string $email): array
    {
        return ['nombre' => 'Nuevo', 'email' => $email, 'password' => 'password123', 'rol' => $rol];
    }

    // --- ADMIN: puede todo -------------------------------------------------

    public function test_admin_crea_gerente_y_mesero(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/usuarios', $this->payload('gerente', 'g@a.test'))->assertCreated();
        $this->postJson('/api/v1/usuarios', $this->payload('mesero', 'm@a.test'))->assertCreated();
    }

    public function test_admin_crea_otro_admin(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/usuarios', $this->payload('admin', 'a2@a.test'))->assertCreated();
    }

    // --- GERENTE: gestiona no-admins --------------------------------------

    public function test_gerente_crea_operador_y_mesero(): void
    {
        Sanctum::actingAs($this->gerente);

        $this->postJson('/api/v1/usuarios', $this->payload('operador', 'op@a.test'))->assertCreated();
        $this->postJson('/api/v1/usuarios', $this->payload('mesero', 'me@a.test'))->assertCreated();
    }

    // --- GERENTE: NO puede con admins (anti-escalada) ---------------------

    public function test_gerente_no_puede_crear_admin(): void
    {
        Sanctum::actingAs($this->gerente);

        $this->postJson('/api/v1/usuarios', $this->payload('admin', 'x@a.test'))
            ->assertStatus(422)
            ->assertJsonPath('errors.rol.0', 'No puedes asignar ese rol.');

        $this->assertDatabaseMissing('usuarios', ['email' => 'x@a.test']);
    }

    public function test_gerente_no_puede_ascender_a_operador_a_admin(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($this->gerente);

        $this->postJson("/api/v1/usuarios/{$operador->id}/rol", ['rol' => 'admin'])
            ->assertStatus(422);
    }

    public function test_gerente_no_puede_reasignar_el_rol_de_un_admin(): void
    {
        Sanctum::actingAs($this->gerente);

        // Degradar a un admin a operador: el rol destino es válido, pero el objetivo es admin.
        $this->postJson("/api/v1/usuarios/{$this->admin->id}/rol", ['rol' => 'operador'])
            ->assertForbidden();
    }

    public function test_gerente_no_puede_editar_ni_desactivar_a_un_admin(): void
    {
        Sanctum::actingAs($this->gerente);

        $this->putJson("/api/v1/usuarios/{$this->admin->id}", ['nombre' => 'Hackeado'])
            ->assertForbidden();

        $this->patchJson("/api/v1/usuarios/{$this->admin->id}/activar", ['activo' => false])
            ->assertForbidden();
    }

    public function test_gerente_si_edita_a_un_no_admin(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($this->gerente);

        $this->putJson("/api/v1/usuarios/{$operador->id}", ['nombre' => 'Renombrado'])
            ->assertOk();
    }
}
