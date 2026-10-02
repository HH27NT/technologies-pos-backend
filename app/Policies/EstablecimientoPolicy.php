<?php

namespace App\Policies;

use App\Models\Establecimiento;
use App\Models\Usuario;

/**
 * Autorización de acceso a Establecimientos (Arq §9): solo SUPER_ADMIN.
 * El super_admin pasa todo vía Gate::before; estos métodos cubren al resto (→ false).
 */
class EstablecimientoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->can('establecimientos.gestionar');
    }

    public function view(Usuario $usuario, Establecimiento $establecimiento): bool
    {
        return $usuario->can('establecimientos.gestionar');
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->can('establecimientos.gestionar');
    }

    public function update(Usuario $usuario, Establecimiento $establecimiento): bool
    {
        return $usuario->can('establecimientos.gestionar');
    }

    public function toggle(Usuario $usuario, Establecimiento $establecimiento): bool
    {
        return $usuario->can('establecimientos.activar');
    }

    public function asignarAdmin(Usuario $usuario, Establecimiento $establecimiento): bool
    {
        return $usuario->can('establecimientos.asignar_admin');
    }

    public function restablecerAcceso(Usuario $usuario, Establecimiento $establecimiento): bool
    {
        return $usuario->can('establecimientos.restablecer_acceso');
    }
}
