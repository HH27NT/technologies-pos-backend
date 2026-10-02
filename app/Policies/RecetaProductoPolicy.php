<?php

namespace App\Policies;

use App\Models\RecetaProducto;
use App\Models\Usuario;

/**
 * Autorización de recetas (M07 · Arq §9): ADMIN del establecimiento.
 * El aislamiento por tenant lo garantiza el TenantScope (otro tenant → 404).
 */
class RecetaProductoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->can('recetas.gestionar');
    }

    public function view(Usuario $usuario, RecetaProducto $receta): bool
    {
        return $usuario->can('recetas.gestionar');
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->can('recetas.gestionar');
    }

    public function update(Usuario $usuario, RecetaProducto $receta): bool
    {
        return $usuario->can('recetas.gestionar');
    }

    public function delete(Usuario $usuario, RecetaProducto $receta): bool
    {
        return $usuario->can('recetas.gestionar');
    }
}
