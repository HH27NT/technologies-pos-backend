<?php

namespace App\Policies;

use App\Models\SesionCaja;
use App\Models\Usuario;

/**
 * Autorización de caja (M10 · matriz Fase 7): ADMIN y OPERADOR del establecimiento
 * abren y cierran caja (permisos caja.abrir/caja.cerrar). El SUPER_ADMIN no opera la
 * caja de un tenant. El aislamiento por tenant lo garantiza el TenantScope.
 * El histórico es del establecimiento y lo ven ambos roles (D2; el acotamiento por
 * turno del operador —P21— se difiere a Reportes, Sprint 11).
 *
 * Lectura del estado (verActual): la compuerta del POS (regla global 5) la necesitan
 * TODOS los que venden — incluido el mesero, que no abre/cierra caja. Por eso `verActual`
 * acepta `caja.ver` además de abrir/cerrar; `viewAny` (histórico, con montos) sigue
 * restringido a quien opera la caja.
 */
class SesionCajaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->can('caja.abrir') || $usuario->can('caja.cerrar');
    }

    /** Lectura del estado de caja para la compuerta del POS (incluye al mesero con caja.ver). */
    public function verActual(Usuario $usuario): bool
    {
        return $usuario->can('caja.ver') || $this->viewAny($usuario);
    }

    public function view(Usuario $usuario, SesionCaja $sesion): bool
    {
        return $this->viewAny($usuario);
    }

    public function abrir(Usuario $usuario): bool
    {
        return $usuario->can('caja.abrir');
    }

    public function cerrar(Usuario $usuario): bool
    {
        return $usuario->can('caja.cerrar');
    }
}
