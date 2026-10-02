<?php

namespace App\Policies;

use App\Models\Autorizacion;
use App\Models\Usuario;

/**
 * Autorización del módulo M14 (matriz Fase 7). El OPERADOR solo SOLICITA
 * (`autorizaciones.solicitar`); el ADMIN ve la bandeja y resuelve
 * (`autorizaciones.aprobar`). El SUPER_ADMIN pasa por Gate::before. El aislamiento por
 * tenant lo garantiza el TenantScope (autorización de otro tenant → 404).
 */
class AutorizacionPolicy
{
    /** Crear una solicitud (operador; el admin ejecuta directo, no solicita). */
    public function solicitar(Usuario $usuario): bool
    {
        return $usuario->can('autorizaciones.solicitar');
    }

    /** Bandeja del admin. */
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->can('autorizaciones.aprobar');
    }

    public function aprobar(Usuario $usuario, Autorizacion $autorizacion): bool
    {
        return $usuario->can('autorizaciones.aprobar');
    }

    public function rechazar(Usuario $usuario, Autorizacion $autorizacion): bool
    {
        return $usuario->can('autorizaciones.aprobar');
    }
}
