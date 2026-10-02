<?php

namespace App\Policies;

use App\Models\ConfiguracionEstablecimiento;
use App\Models\Usuario;

/**
 * Autorización de la configuración del establecimiento (M03 · Arq §9).
 * La consulta y edita el ADMIN del propio tenant (permiso configuracion.editar);
 * el SUPER_ADMIN pasa todo vía Gate::before.
 */
class ConfiguracionEstablecimientoPolicy
{
    public function view(Usuario $usuario, ConfiguracionEstablecimiento $configuracion): bool
    {
        return $usuario->can('configuracion.editar');
    }

    public function update(Usuario $usuario, ConfiguracionEstablecimiento $configuracion): bool
    {
        return $usuario->can('configuracion.editar');
    }
}
