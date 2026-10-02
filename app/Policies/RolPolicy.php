<?php

namespace App\Policies;

use App\Models\Rol;
use App\Models\Usuario;

/**
 * Autorización del EDITOR DE ROLES (M04).
 *
 * `roles.gestionar` lo tiene SOLO el admin, nunca el gerente. El motivo es de seguridad,
 * no de jerarquía: quien define permisos puede fabricarse un rol equivalente a admin y
 * asignárselo, lo que evaporaría la salvaguarda anti-escalada de UsuarioPolicy. Es el
 * mismo criterio de `usuarios.gestionar_admins`.
 *
 * Los roles del sistema (los presets del catálogo) son de SOLO LECTURA: se resincronizan
 * en cada arranque contra CatalogoRoles, así que cualquier edición se perdería en silencio
 * en el siguiente despliegue. Para personalizar se CLONA (ver GuardarRolService).
 *
 * El aislamiento por tenant lo garantiza el TenantScope del modelo Rol: un id de otro
 * establecimiento da 404 antes de llegar aquí.
 */
class RolPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        // Ver el listado basta con poder gestionar personal: el selector de rol del alta
        // de usuarios se alimenta de aquí, y el gerente da de alta personal.
        return $usuario->can('usuarios.gestionar');
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->can('roles.gestionar');
    }

    public function update(Usuario $usuario, Rol $rol): bool
    {
        return $usuario->can('roles.gestionar') && ! $rol->esDelSistema();
    }

    public function delete(Usuario $usuario, Rol $rol): bool
    {
        return $usuario->can('roles.gestionar') && ! $rol->esDelSistema();
    }
}
