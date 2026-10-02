<?php

namespace Tests\Feature\Establecimientos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class EstablecimientoTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_crea_establecimiento_con_config_admin_y_roles(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $respuesta = $this->postJson('/api/v1/establecimientos', [
            'nombre' => 'Bar La Cantina',
            'admin' => [
                'nombre' => 'Ana',
                'email' => 'ana@cantina.test',
                'password' => 'password123',
            ],
        ])->assertCreated();

        $id = $respuesta->json('data.id');

        $this->assertDatabaseHas('establecimientos', ['id' => $id, 'nombre' => 'Bar La Cantina']);
        $this->assertDatabaseHas('configuracion_establecimiento', ['id_establecimiento' => $id]);
        $this->assertDatabaseHas('usuarios', ['email' => 'ana@cantina.test', 'id_establecimiento' => $id]);
        $this->assertDatabaseHas('roles', ['name' => 'admin', 'id_establecimiento' => $id]);
        $this->assertDatabaseHas('roles', ['name' => 'operador', 'id_establecimiento' => $id]);
    }

    public function test_super_admin_crea_establecimiento_con_personal_adicional(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $respuesta = $this->postJson('/api/v1/establecimientos', [
            'nombre' => 'Bar Equipo Completo',
            'admin' => ['nombre' => 'Ana', 'email' => 'ana@equipo.test', 'password' => 'password123'],
            'personal' => [
                ['rol' => 'gerente', 'nombre' => 'Gere', 'email' => 'gere@equipo.test', 'password' => 'password123'],
                ['rol' => 'operador', 'nombre' => 'Opera', 'username' => 'opera_equipo', 'password' => 'password123'],
                ['rol' => 'mesero', 'nombre' => 'Mese', 'username' => 'mese_equipo', 'password' => 'password123'],
            ],
        ])->assertCreated();

        $id = $respuesta->json('data.id');

        $this->assertDatabaseHas('usuarios', ['email' => 'gere@equipo.test', 'id_establecimiento' => $id]);
        $this->assertDatabaseHas('usuarios', ['username' => 'opera_equipo', 'id_establecimiento' => $id]);
        $this->assertDatabaseHas('usuarios', ['username' => 'mese_equipo', 'id_establecimiento' => $id]);
    }

    public function test_no_se_puede_dar_de_alta_un_segundo_admin_como_personal(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/establecimientos', [
            'nombre' => 'Bar Sin Segundo Admin',
            'admin' => ['nombre' => 'Ana', 'email' => 'ana@sinsegundo.test', 'password' => 'password123'],
            'personal' => [
                ['rol' => 'admin', 'nombre' => 'Otro', 'email' => 'otro@sinsegundo.test', 'password' => 'password123'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['personal.0.rol']);
    }

    public function test_personal_con_correo_repetido_entre_si_se_rechaza(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/establecimientos', [
            'nombre' => 'Bar Correos Repetidos',
            'admin' => ['nombre' => 'Ana', 'email' => 'repetido@dup.test', 'password' => 'password123'],
            'personal' => [
                ['rol' => 'gerente', 'nombre' => 'Gere', 'email' => 'repetido@dup.test', 'password' => 'password123'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['personal.0.email']);
    }

    public function test_un_admin_de_tenant_no_accede_a_plataforma(): void
    {
        ['admin' => $admin] = $this->nuevoTenant();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/establecimientos')->assertStatus(403);
    }

    public function test_super_admin_lista_establecimientos(): void
    {
        $this->nuevoTenant('uno');
        $this->nuevoTenant('dos');
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/establecimientos')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_desactivar_establecimiento(): void
    {
        ['establecimiento' => $est] = $this->nuevoTenant();
        Sanctum::actingAs($this->superAdmin());

        $this->patchJson("/api/v1/establecimientos/{$est->id}/activar", ['activo' => false])->assertOk();

        $this->assertDatabaseHas('establecimientos', ['id' => $est->id, 'activo' => false]);
    }

    public function test_asignar_admin_a_un_operador(): void
    {
        ['establecimiento' => $est] = $this->nuevoTenant();
        $operador = $this->crearUsuarioEnTenant($est->id, 'operador');

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/v1/establecimientos/{$est->id}/asignar-admin", [
            'id_usuario' => $operador->id,
        ])->assertOk();

        $rolAdmin = Role::where('name', 'admin')->where('id_establecimiento', $est->id)->first();
        $this->assertEquals($rolAdmin->id, $operador->fresh()->id_rol);
    }
}
