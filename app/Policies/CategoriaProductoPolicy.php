<?php

namespace App\Policies;

use App\Models\CategoriaProducto;
use App\Models\Usuario;

/**
 * Autorización del catálogo de categorías (M05 · Arq §9). Escribir es del ADMIN
 * (`categorias.gestionar`); LEER exige `categorias.ver`, que también tiene el operador:
 * el filtro por categoría del POS lo necesita.
 * El aislamiento por tenant lo garantiza el TenantScope (otro tenant → 404).
 */
class CategoriaProductoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->can('categorias.ver');
    }

    public function view(Usuario $usuario, CategoriaProducto $categoria): bool
    {
        return $usuario->can('categorias.ver');
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->can('categorias.gestionar');
    }

    public function update(Usuario $usuario, CategoriaProducto $categoria): bool
    {
        return $usuario->can('categorias.gestionar');
    }

    public function cambiarEstado(Usuario $usuario, CategoriaProducto $categoria): bool
    {
        return $usuario->can('categorias.gestionar');
    }
}
