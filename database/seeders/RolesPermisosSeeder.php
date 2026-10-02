<?php

namespace Database\Seeders;

use App\Domain\Usuarios\CatalogoRoles;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Siembra el catálogo de permisos y los roles PLANTILLA (team NULL), idempotente.
 *
 * No decide nada: la matriz rol↔permiso vive en App\Domain\Usuarios\CatalogoRoles
 * (fuente única). Este seeder solo la materializa en la base. La asignación real
 * por establecimiento (teams de Spatie) la resuelve ProveedorRolesTenant al crear
 * cada tenant.
 */
class RolesPermisosSeeder extends Seeder
{
    public function run(): void
    {
        // Guard estable del modelo Usuario (no config('auth.defaults.guard'), que Sanctum muta).
        $guard = Guard::getDefaultName(Usuario::class);

        // El catálogo se siembra a nivel global (team nulo).
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        foreach (CatalogoRoles::PERMISOS as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => $guard]);
        }

        foreach (CatalogoRoles::todos() as $nombre => $permisos) {
            Role::firstOrCreate(['name' => $nombre, 'guard_name' => $guard])
                ->syncPermissions($this->permisos($permisos, $guard));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Resuelve los permisos por nombre con un guard explícito (evita el guard activo). */
    private function permisos(array $nombres, string $guard)
    {
        return Permission::whereIn('name', $nombres)->where('guard_name', $guard)->get();
    }
}
