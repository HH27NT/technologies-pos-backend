<?php

namespace App\Domain\Usuarios\Services;

use App\Models\Usuario;
use App\Support\Exceptions\UltimoAdminException;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * Garantiza la regla "debe existir al menos un ADMIN activo por establecimiento"
 * (M04). Se invoca ANTES de desactivar o degradar de rol a un usuario.
 */
class UltimoAdminGuard
{
    /** Lanza UltimoAdminException si quitar a este usuario dejaría al tenant sin admin activo. */
    public function asegurarNoEsUltimoAdmin(Usuario $usuario): void
    {
        $idEstablecimiento = $usuario->id_establecimiento;

        if ($idEstablecimiento === null) {
            return; // super_admin: no aplica.
        }

        $rolAdmin = Role::where('name', 'admin')
            ->where('guard_name', Guard::getDefaultName(Usuario::class))
            ->where('id_establecimiento', $idEstablecimiento)
            ->first();

        if ($rolAdmin === null) {
            return;
        }

        $esAdminActivo = $usuario->activo && (int) $usuario->id_rol === $rolAdmin->id;

        if (! $esAdminActivo) {
            return; // No es un admin activo: removerlo no afecta la regla.
        }

        $adminsActivos = Usuario::where('id_establecimiento', $idEstablecimiento)
            ->where('activo', true)
            ->where('id_rol', $rolAdmin->id)
            ->count();

        if ($adminsActivos <= 1) {
            throw new UltimoAdminException;
        }
    }
}
