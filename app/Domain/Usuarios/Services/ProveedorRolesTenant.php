<?php

namespace App\Domain\Usuarios\Services;

use App\Domain\Usuarios\CatalogoRoles;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Obtiene (creando si hace falta) un rol Spatie del team de un establecimiento, con
 * sus permisos sincronizados según CatalogoRoles (fuente única de la matriz).
 * Centraliza el manejo del team_id de Spatie para todos los servicios de tenant.
 *
 * El guard se resuelve con Guard::getDefaultName(Usuario) — estable como `web` —
 * y NO con config('auth.defaults.guard'), porque Sanctum::actingAs lo cambia a
 * `sanctum` en tiempo de petición.
 */
class ProveedorRolesTenant
{
    public function __construct(private readonly PermissionRegistrar $permisos) {}

    public function obtener(string $rol, int $idEstablecimiento): Role
    {
        $guard = Guard::getDefaultName(Usuario::class);

        $teamAnterior = $this->permisos->getPermissionsTeamId();
        $this->permisos->setPermissionsTeamId($idEstablecimiento);

        $role = Role::firstOrCreate([
            'name' => $rol,
            'guard_name' => $guard,
            'id_establecimiento' => $idEstablecimiento,
        ]);

        // Se pasan modelos Permission (no nombres) para fijar el guard explícitamente.
        $permisos = Permission::whereIn('name', CatalogoRoles::permisosDe($rol))
            ->where('guard_name', $guard)
            ->get();
        $role->syncPermissions($permisos);

        $this->permisos->setPermissionsTeamId($teamAnterior);
        $this->permisos->forgetCachedPermissions();

        return $role;
    }

    /**
     * Resuelve el rol que se va a ASIGNAR a un usuario, sin tocar sus permisos si es
     * un rol a medida del tenant.
     *
     * La distinción es crítica y no cosmética: `obtener()` sincroniza el rol contra el
     * catálogo, y para un rol a medida `CatalogoRoles::permisosDe()` devuelve [] — o sea
     * que usar `obtener()` aquí VACIARÍA los permisos del rol en el acto de asignarlo.
     * Los presets sí pasan por `obtener()`, para garantizar que estén al día.
     *
     * @throws ModelNotFoundException si el rol no es de este tenant
     */
    public function resolverParaAsignar(string $rol, int $idEstablecimiento): Role
    {
        if (in_array($rol, CatalogoRoles::nombresTenant(), true)) {
            return $this->obtener($rol, $idEstablecimiento);
        }

        return Role::where('name', $rol)
            ->where('guard_name', Guard::getDefaultName(Usuario::class))
            ->where('id_establecimiento', $idEstablecimiento)
            ->firstOrFail();
    }

    /** Ejecuta una operación de roles con el team de Spatie fijado y luego lo restaura. */
    public function enTeam(int $idEstablecimiento, callable $callback): mixed
    {
        $teamAnterior = $this->permisos->getPermissionsTeamId();
        $this->permisos->setPermissionsTeamId($idEstablecimiento);

        try {
            return $callback();
        } finally {
            $this->permisos->setPermissionsTeamId($teamAnterior);
        }
    }
}
