<?php

namespace Tests\Unit\Establecimientos;

use App\Domain\Establecimientos\Services\CrearEstablecimientoService;
use App\Domain\Usuarios\CatalogoRoles;
use App\Models\ConfiguracionEstablecimiento;
use App\Models\Establecimiento;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CrearEstablecimientoServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermisosSeeder::class);
    }

    public function test_crea_establecimiento_configuracion_admin_y_roles_atomicamente(): void
    {
        $resultado = app(CrearEstablecimientoService::class)->crear([
            'nombre' => 'Cervecería Test',
            'admin' => [
                'nombre' => 'Admin Inicial',
                'email' => 'admin@cerveceria.test',
                'password' => 'password123',
            ],
        ]);

        $est = $resultado['establecimiento'];
        $admin = $resultado['admin'];

        $this->assertDatabaseCount('establecimientos', 1);
        $this->assertInstanceOf(Establecimiento::class, $est);
        $this->assertTrue(ConfiguracionEstablecimiento::where('id_establecimiento', $est->id)->exists());

        $rolAdmin = Role::where('name', 'admin')->where('id_establecimiento', $est->id)->first();
        $this->assertNotNull($rolAdmin);

        // El admin inicial está enlazado por cache (id_rol) y por Spatie (model_has_roles).
        $this->assertEquals($rolAdmin->id, $admin->id_rol);
        app(PermissionRegistrar::class)->setPermissionsTeamId($est->id);
        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->assertTrue($admin->fresh()->can('usuarios.gestionar'));
    }

    /**
     * Regresión: el servicio provisionaba una lista literal ('admin', 'operador') que se
     * quedó atrás al introducir gerente/mesero, y como `GET /roles` devuelve las filas
     * materializadas, los tenants nuevos solo ofrecían 2 roles al dar de alta usuarios.
     * Se afirma contra el catálogo completo para que agregar un rol extienda el test solo.
     */
    public function test_provisiona_todos_los_roles_del_catalogo_para_el_tenant(): void
    {
        $est = app(CrearEstablecimientoService::class)->crear([
            'nombre' => 'Bar Catálogo',
            'admin' => ['nombre' => 'A', 'email' => 'a@catalogo.test', 'password' => 'password123'],
        ])['establecimiento'];

        $rolesDelTenant = Role::where('id_establecimiento', $est->id)->pluck('name')->all();

        sort($rolesDelTenant);
        $esperados = CatalogoRoles::nombresTenant();
        sort($esperados);

        $this->assertSame($esperados, $rolesDelTenant);
    }

    /** Cada rol provisionado nace con los permisos de su paquete, no vacío. */
    public function test_los_roles_provisionados_traen_los_permisos_de_su_paquete(): void
    {
        $est = app(CrearEstablecimientoService::class)->crear([
            'nombre' => 'Bar Paquetes',
            'admin' => ['nombre' => 'A', 'email' => 'a@paquetes.test', 'password' => 'password123'],
        ])['establecimiento'];

        foreach (CatalogoRoles::TENANT as $nombre => $permisos) {
            $rol = Role::where('name', $nombre)->where('id_establecimiento', $est->id)->first();

            $this->assertNotNull($rol, "Falta el rol {$nombre} del tenant.");
            $this->assertEqualsCanonicalizing(
                $permisos,
                $rol->permissions->pluck('name')->all(),
                "El rol {$nombre} no coincide con su paquete del catálogo.",
            );
        }
    }

    /** Personal adicional (gerente/operador/mesero) se puede dar de alta junto con el establecimiento. */
    public function test_crea_personal_adicional_junto_con_el_establecimiento(): void
    {
        $resultado = app(CrearEstablecimientoService::class)->crear([
            'nombre' => 'Bar Personal',
            'admin' => ['nombre' => 'Dueño', 'email' => 'dueno@personal.test', 'password' => 'password123'],
            'personal' => [
                ['rol' => 'gerente', 'nombre' => 'Gere', 'email' => 'gere@personal.test', 'password' => 'password123'],
                ['rol' => 'operador', 'nombre' => 'Opera', 'username' => 'opera_personal', 'password' => 'password123'],
                ['rol' => 'mesero', 'nombre' => 'Mese', 'username' => 'mese_personal', 'password' => 'password123'],
            ],
        ]);

        $est = $resultado['establecimiento'];
        $this->assertCount(3, $resultado['personal']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($est->id);
        $rolesCreados = collect($resultado['personal'])->map(fn ($u) => $u->fresh()->getRoleNames()->first())->all();
        $this->assertEqualsCanonicalizing(['gerente', 'operador', 'mesero'], $rolesCreados);

        foreach ($resultado['personal'] as $usuario) {
            $this->assertSame($est->id, $usuario->id_establecimiento);
        }
    }

    public function test_sin_personal_el_establecimiento_se_crea_igual(): void
    {
        $resultado = app(CrearEstablecimientoService::class)->crear([
            'nombre' => 'Bar Solo',
            'admin' => ['nombre' => 'A', 'email' => 'a@solo.test', 'password' => 'password123'],
        ]);

        $this->assertSame([], $resultado['personal']);
    }

    public function test_el_admin_inicial_tiene_los_permisos_de_la_matriz(): void
    {
        $resultado = app(CrearEstablecimientoService::class)->crear([
            'nombre' => 'Bar Permisos',
            'admin' => ['nombre' => 'A', 'email' => 'a@permisos.test', 'password' => 'password123'],
        ]);

        $est = $resultado['establecimiento'];
        app(PermissionRegistrar::class)->setPermissionsTeamId($est->id);
        $admin = $resultado['admin']->fresh();

        $this->assertTrue($admin->can('caja.abrir'));
        $this->assertFalse($admin->can('establecimientos.gestionar')); // exclusivo de super_admin
    }
}
