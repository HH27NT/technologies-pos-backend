<?php

namespace App\Policies;

use App\Models\Insumo;
use App\Models\Usuario;

/**
 * Autorización de insumos (M08 · Arq §9): ADMIN del establecimiento.
 * El aislamiento por tenant lo garantiza el TenantScope (otro tenant → 404).
 * El kardex (lectura de movimientos del insumo) se gobierna con view.
 */
class InsumoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->can('insumos.gestionar');
    }

    public function view(Usuario $usuario, Insumo $insumo): bool
    {
        return $usuario->can('insumos.gestionar');
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->can('insumos.gestionar');
    }

    public function update(Usuario $usuario, Insumo $insumo): bool
    {
        return $usuario->can('insumos.gestionar');
    }

    public function cambiarEstado(Usuario $usuario, Insumo $insumo): bool
    {
        return $usuario->can('insumos.gestionar');
    }
}
