<?php

namespace App\Domain\Usuarios;

use App\Models\Rol;
use App\Models\Usuario;

/**
 * Qué roles puede ASIGNAR un actor a otros usuarios (M04, modelo de 5 roles + a medida).
 *
 * Se consulta la tabla del ESTABLECIMIENTO, no la lista del catálogo: desde el editor de
 * roles un tenant puede tener roles propios ("Cajero nocturno") que son perfectamente
 * asignables aunque no existan en CatalogoRoles. Validar contra el catálogo los rechazaría
 * con un "No puedes asignar ese rol" imposible de entender.
 *
 * Salvaguarda anti-escalada, facet A: `admin` solo se ofrece a quien tiene
 * `usuarios.gestionar_admins` (el gerente NO). La facet B (no tocar a un usuario que YA
 * es admin) vive en UsuarioPolicy. Ver docs/MatrizRoles.md.
 *
 * `super_admin` nunca aparece: es rol de plataforma (sin establecimiento) y el scope de
 * tenant lo deja fuera.
 */
class RolesAsignables
{
    /** @return string[] nombres técnicos de rol que el actor puede asignar */
    public static function para(Usuario $actor): array
    {
        $roles = Rol::query()
            ->where('id_establecimiento', $actor->id_establecimiento)
            ->pluck('name')
            ->all();

        if ($actor->can('usuarios.gestionar_admins')) {
            return $roles;
        }

        return array_values(array_diff($roles, [CatalogoRoles::ROL_ADMIN]));
    }
}
