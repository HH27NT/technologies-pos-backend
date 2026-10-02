<?php

namespace App\Policies;

use App\Models\Usuario;

/**
 * Autorización de acceso a Usuarios (Arq §9): gestionar personal exige `usuarios.gestionar`
 * (admin o gerente). El aislamiento por tenant lo garantiza el TenantScope (otro tenant → 404).
 * La regla del último admin activo vive en el servicio, no aquí.
 *
 * Salvaguarda anti-escalada (facet B): modificar/desactivar/reasignar a un usuario que YA es
 * admin exige `usuarios.gestionar_admins`; el gerente (que carece de él) no puede tocar admins.
 * La otra mitad (facet A: no crear/asignar el rol admin) vive en los Form Requests.
 */
class UsuarioPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->can('usuarios.gestionar');
    }

    public function view(Usuario $actor, Usuario $objetivo): bool
    {
        return $actor->can('usuarios.gestionar');
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->can('usuarios.gestionar');
    }

    public function update(Usuario $actor, Usuario $objetivo): bool
    {
        return $this->puedeGestionar($actor, $objetivo);
    }

    public function cambiarEstado(Usuario $actor, Usuario $objetivo): bool
    {
        return $this->puedeGestionar($actor, $objetivo);
    }

    public function asignarRol(Usuario $actor, Usuario $objetivo): bool
    {
        return $this->puedeGestionar($actor, $objetivo);
    }

    /**
     * Gestiona personal, pero no puede tocar a un administrador salvo que tenga
     * `usuarios.gestionar_admins`. (El super_admin pasa antes por Gate::before.)
     *
     * "Es administrador" se mide por el PERMISO del objetivo y no por `hasRole('admin')`
     * (regla de oro #3: gatear por permiso, nunca por nombre de rol). Con el editor de
     * roles a medida la diferencia dejó de ser teórica: un rol propio con
     * `usuarios.gestionar_admins` es un admin en todo salvo el nombre, y mirando el
     * nombre quedaba desprotegido — un gerente podría desactivarlo.
     */
    private function puedeGestionar(Usuario $actor, Usuario $objetivo): bool
    {
        if (! $actor->can('usuarios.gestionar')) {
            return false;
        }

        return $actor->can('usuarios.gestionar_admins') || ! $objetivo->can('usuarios.gestionar_admins');
    }
}
