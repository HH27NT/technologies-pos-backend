<?php

namespace Tests\Feature\Usuarios;

use App\Domain\Usuarios\CatalogoPermisos;
use App\Domain\Usuarios\CatalogoRoles;
use App\Models\Establecimiento;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * Editor de roles a medida (M04). Cubre las tres reglas que lo hacen seguro:
 *  1. Solo el admin lo usa (`roles.gestionar`); el gerente no.
 *  2. Nadie otorga permisos que no tiene (contención de privilegios).
 *  3. Los presets del catálogo son de solo lectura; se clonan.
 * Y el bug latente que introduce: asignar un rol a medida NO debe vaciar sus permisos.
 */
class EditorRolesTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $gerente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('editor');
        $this->gerente = $this->crearUsuarioEnTenant($this->establecimiento->id, 'gerente');
    }

    private function payload(array $sobrescribir = []): array
    {
        return array_merge([
            'etiqueta' => 'Cajero nocturno',
            'descripcion' => 'Cobra y cierra caja en el turno de noche.',
            'permisos' => ['ordenes.crear', 'ordenes.cobrar', 'caja.ver'],
        ], $sobrescribir);
    }

    // --- 1. Quién puede usar el editor -------------------------------------

    public function test_admin_crea_un_rol_a_medida(): void
    {
        Sanctum::actingAs($this->admin);

        $respuesta = $this->postJson('/api/v1/roles', $this->payload())->assertCreated();

        $respuesta->assertJsonPath('data.etiqueta', 'Cajero nocturno');
        $respuesta->assertJsonPath('data.es_sistema', false);

        $rol = Rol::withoutGlobalScopes()->where('etiqueta', 'Cajero nocturno')->firstOrFail();
        $this->assertSame($this->establecimiento->id, $rol->id_establecimiento);
        $this->assertEqualsCanonicalizing(
            ['ordenes.crear', 'ordenes.cobrar', 'caja.ver'],
            $rol->permissions->pluck('name')->all(),
        );
    }

    public function test_gerente_no_puede_crear_roles(): void
    {
        Sanctum::actingAs($this->gerente);

        $this->postJson('/api/v1/roles', $this->payload())->assertForbidden();

        $this->assertDatabaseMissing('roles', ['etiqueta' => 'Cajero nocturno']);
    }

    public function test_gerente_si_lista_los_roles_para_dar_de_alta_personal(): void
    {
        Sanctum::actingAs($this->gerente);

        $this->getJson('/api/v1/roles')->assertOk();
    }

    // --- 2. Contención de privilegios (anti-escalada) ----------------------

    public function test_no_se_pueden_otorgar_permisos_que_el_actor_no_tiene(): void
    {
        // El admin no tiene permisos de PLATAFORMA, así que no puede fabricarlos.
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/roles', $this->payload([
            'permisos' => ['ordenes.crear', 'establecimientos.gestionar'],
        ]))->assertStatus(422);
    }

    public function test_un_rol_a_medida_no_puede_llamarse_como_uno_del_sistema(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/roles', $this->payload(['etiqueta' => 'Administrador']))
            ->assertStatus(422)
            ->assertJsonPath('errors.etiqueta.0', 'Ese nombre está reservado para un rol del sistema. Elige otro.');
    }

    // --- 3. Los presets son de solo lectura --------------------------------

    public function test_un_rol_del_sistema_no_se_puede_editar_ni_eliminar(): void
    {
        Sanctum::actingAs($this->admin);

        $operador = Rol::withoutGlobalScopes()
            ->where('id_establecimiento', $this->establecimiento->id)
            ->where('name', 'operador')
            ->firstOrFail();

        $this->putJson("/api/v1/roles/{$operador->id}", $this->payload(['etiqueta' => 'Operador tuneado']))
            ->assertForbidden();

        $this->deleteJson("/api/v1/roles/{$operador->id}")->assertForbidden();
    }

    public function test_clonar_un_preset_copia_sus_permisos_a_un_rol_propio(): void
    {
        Sanctum::actingAs($this->admin);

        $mesero = Rol::withoutGlobalScopes()
            ->where('id_establecimiento', $this->establecimiento->id)
            ->where('name', 'mesero')
            ->firstOrFail();

        $permisosDelPreset = $mesero->permissions->pluck('name')->all();

        $this->postJson('/api/v1/roles', [
            'etiqueta' => 'Mesero de terraza',
            'permisos' => $permisosDelPreset,
            'clonar_de' => $mesero->id,
        ])->assertCreated();

        $clon = Rol::withoutGlobalScopes()->where('etiqueta', 'Mesero de terraza')->firstOrFail();

        $this->assertFalse($clon->esDelSistema());
        $this->assertEqualsCanonicalizing($permisosDelPreset, $clon->permissions->pluck('name')->all());
        // El preset queda intacto: clonar no lo toca.
        $this->assertEqualsCanonicalizing($permisosDelPreset, $mesero->fresh()->permissions->pluck('name')->all());
    }

    // --- Ciclo de vida del rol a medida ------------------------------------

    public function test_editar_un_rol_a_medida_cambia_sus_permisos(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/roles', $this->payload())->json('data.id');

        $this->putJson("/api/v1/roles/{$id}", $this->payload([
            'etiqueta' => 'Cajero nocturno',
            'permisos' => ['ordenes.crear', 'ordenes.cobrar', 'caja.ver', 'ordenes.aplicar_descuento'],
        ]))->assertOk();

        $rol = Rol::withoutGlobalScopes()->findOrFail($id);
        $this->assertTrue($rol->permissions->pluck('name')->contains('ordenes.aplicar_descuento'));
    }

    public function test_no_se_elimina_un_rol_con_usuarios_asignados(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/roles', $this->payload())->json('data.id');
        $nombre = Rol::withoutGlobalScopes()->findOrFail($id)->name;

        $this->crearUsuarioEnTenant($this->establecimiento->id, $nombre);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/v1/roles/{$id}")->assertStatus(409);

        $this->assertDatabaseHas('roles', ['id' => $id]);
    }

    public function test_se_elimina_un_rol_a_medida_sin_usuarios(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/roles', $this->payload())->json('data.id');

        $this->deleteJson("/api/v1/roles/{$id}")->assertOk();

        $this->assertDatabaseMissing('roles', ['id' => $id]);
    }

    // --- El bug latente: asignar un rol a medida no debe vaciarlo ----------

    public function test_asignar_un_rol_a_medida_conserva_sus_permisos(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/roles', $this->payload())->json('data.id');
        $rol = Rol::withoutGlobalScopes()->findOrFail($id);

        // Alta de usuario con el rol a medida: antes esto pasaba por
        // ProveedorRolesTenant::obtener(), que lo habría sincronizado contra el
        // catálogo (que no lo conoce) y le habría borrado los permisos.
        $usuario = $this->crearUsuarioEnTenant($this->establecimiento->id, $rol->name);

        $this->assertSame($rol->id, $usuario->id_rol);
        $this->assertEqualsCanonicalizing(
            ['ordenes.crear', 'ordenes.cobrar', 'caja.ver'],
            $rol->fresh()->permissions->pluck('name')->all(),
        );
    }

    public function test_el_usuario_con_rol_a_medida_ejerce_sus_permisos(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/roles', $this->payload())->json('data.id');
        $rol = Rol::withoutGlobalScopes()->findOrFail($id);

        $usuario = $this->crearUsuarioEnTenant($this->establecimiento->id, $rol->name);

        Sanctum::actingAs($usuario->fresh());

        $respuesta = $this->getJson('/api/v1/auth/me')->assertOk();
        $permisos = $respuesta->json('data.permisos');

        $this->assertContains('ordenes.cobrar', $permisos);
        $this->assertNotContains('usuarios.gestionar', $permisos);
    }

    // --- Catálogo de permisos para pintar el editor ------------------------

    public function test_el_catalogo_de_permisos_no_ofrece_los_de_plataforma(): void
    {
        Sanctum::actingAs($this->admin);

        $grupos = $this->getJson('/api/v1/roles/permisos')->assertOk()->json('data');

        $nombres = collect($grupos)->pluck('permisos')->flatten(1)->pluck('nombre');

        $this->assertTrue($nombres->contains('ordenes.cobrar'));
        $this->assertFalse($nombres->contains('establecimientos.gestionar'));
        $this->assertFalse($nombres->contains('metricas.globales'));
    }

    /**
     * Invariante que hace usable el editor: el admin es autoridad máxima del tenant, así
     * que tiene todo lo otorgable. Un hueco aquí le impide crear un rol legítimo, porque
     * la contención de privilegios se lo bloquea — pasó al clonar `mesero`, que llevaba
     * `autorizaciones.solicitar` y `reportes.ver_limitado` fuera del paquete del admin.
     */
    public function test_el_admin_tiene_todos_los_permisos_otorgables(): void
    {
        $this->assertEqualsCanonicalizing(
            CatalogoPermisos::asignables(),
            CatalogoRoles::PERMISOS_ADMIN,
            'El admin debe poder otorgar cualquier permiso de tenant al crear roles a medida.',
        );
    }

    /** El admin puede clonar CUALQUIER preset: es el caso de uso principal del editor. */
    public function test_el_admin_puede_clonar_todos_los_presets(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (CatalogoRoles::nombresTenant() as $preset) {
            $rol = Rol::withoutGlobalScopes()
                ->where('id_establecimiento', $this->establecimiento->id)
                ->where('name', $preset)
                ->firstOrFail();

            $this->postJson('/api/v1/roles', [
                'etiqueta' => 'Copia de '.$preset,
                'permisos' => $rol->permissions->pluck('name')->all(),
                'clonar_de' => $rol->id,
            ])->assertCreated();
        }
    }

    /**
     * La salvaguarda anti-escalada debe cubrir a un usuario con un rol A MEDIDA que
     * confiere autoridad sobre admins: es un admin en todo salvo el nombre. Antes
     * UsuarioPolicy miraba `hasRole('admin')` y el gerente podía desactivarlo.
     */
    public function test_un_rol_a_medida_con_autoridad_sobre_admins_queda_protegido(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/roles', $this->payload([
            'etiqueta' => 'Codueño',
            'permisos' => ['usuarios.gestionar', 'usuarios.gestionar_admins'],
        ]))->json('data.id');

        $nombre = Rol::withoutGlobalScopes()->findOrFail($id)->name;
        $codueno = $this->crearUsuarioEnTenant($this->establecimiento->id, $nombre);

        Sanctum::actingAs($this->gerente);

        $this->putJson("/api/v1/usuarios/{$codueno->id}", ['nombre' => 'Degradado'])->assertForbidden();
        $this->patchJson("/api/v1/usuarios/{$codueno->id}/activar", ['activo' => false])->assertForbidden();
    }

    // --- Aislamiento por tenant --------------------------------------------

    public function test_no_se_puede_editar_un_rol_de_otro_establecimiento(): void
    {
        ['establecimiento' => $otro] = $this->nuevoTenant('ajeno');

        $rolAjeno = Rol::withoutGlobalScopes()
            ->where('id_establecimiento', $otro->id)
            ->where('name', 'operador')
            ->firstOrFail();

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/roles/{$rolAjeno->id}", $this->payload())->assertNotFound();
    }
}
