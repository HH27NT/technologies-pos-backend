<?php

namespace App\Policies;

use App\Models\Impresora;
use App\Models\Usuario;

/**
 * Autorización del catálogo de impresoras (M13 · Arq §9): ADMIN del establecimiento.
 * El aislamiento por tenant lo garantiza el TenantScope (otro tenant → 404).
 */
class ImpresoraPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->can('impresoras.gestionar');
    }

    public function view(Usuario $usuario, Impresora $impresora): bool
    {
        return $usuario->can('impresoras.gestionar');
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->can('impresoras.gestionar');
    }

    public function update(Usuario $usuario, Impresora $impresora): bool
    {
        return $usuario->can('impresoras.gestionar');
    }

    public function cambiarEstado(Usuario $usuario, Impresora $impresora): bool
    {
        return $usuario->can('impresoras.gestionar');
    }
}
