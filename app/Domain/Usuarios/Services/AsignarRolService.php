<?php

namespace App\Domain\Usuarios\Services;

use App\Domain\Usuarios\Events\UsuarioModificado;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Cambia el rol de un usuario dentro de su establecimiento (M04): tanto los presets
 * del catálogo como los roles a medida del editor.
 * Si la degradación dejaría al tenant sin admin activo, se bloquea (último admin).
 */
class AsignarRolService
{
    public function __construct(
        private readonly ProveedorRolesTenant $roles,
        private readonly UltimoAdminGuard $guard,
    ) {}

    public function asignar(Usuario $usuario, string $rol): Usuario
    {
        return DB::transaction(function () use ($usuario, $rol) {
            if ($rol !== 'admin') {
                $this->guard->asegurarNoEsUltimoAdmin($usuario);
            }

            $idEstablecimiento = (int) $usuario->id_establecimiento;
            // Ver ProveedorRolesTenant::resolverParaAsignar: usar obtener() aquí borraría
            // los permisos de los roles a medida al asignarlos.
            $nuevoRol = $this->roles->resolverParaAsignar($rol, $idEstablecimiento);

            $rolAntes = $usuario->id_rol;

            $this->roles->enTeam($idEstablecimiento, fn () => $usuario->syncRoles([$nuevoRol]));

            $usuario->id_rol = $nuevoRol->id;
            $usuario->save();

            event(new UsuarioModificado(
                $usuario,
                ['id_rol' => $rolAntes],
                ['id_rol' => $nuevoRol->id, 'rol' => $rol],
                'usuario.rol_cambiado',
            ));

            return $usuario;
        });
    }
}
