<?php

namespace App\Policies;

use App\Models\Usuario;

/**
 * M16/M15 · Consulta de la bitácora. El ADMIN ve la auditoría de SU establecimiento
 * (`auditoria.ver`); el SUPER_ADMIN ve la auditoría global de la plataforma
 * (`auditoria.global`, incluidas las acciones sin establecimiento). El OPERADOR no
 * consulta auditoría.
 */
class AuditoriaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->can('auditoria.ver');
    }

    public function global(Usuario $usuario): bool
    {
        return $usuario->can('auditoria.global');
    }
}
