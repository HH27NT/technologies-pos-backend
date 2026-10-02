<?php

namespace App\Policies;

use App\Models\Producto;
use App\Models\Usuario;

/**
 * Autorización del catálogo de productos (M06 · Arq §9). Escribir es del ADMIN
 * (`productos.gestionar`); LEER exige `productos.ver`, que también tiene el operador:
 * necesita la rejilla de productos para vender, pero no puede tocar el catálogo.
 * El aislamiento por tenant lo garantiza el TenantScope (otro tenant → 404).
 */
class ProductoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->can('productos.ver');
    }

    public function view(Usuario $usuario, Producto $producto): bool
    {
        return $usuario->can('productos.ver');
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->can('productos.gestionar');
    }

    public function update(Usuario $usuario, Producto $producto): bool
    {
        return $usuario->can('productos.gestionar');
    }

    public function cambiarEstado(Usuario $usuario, Producto $producto): bool
    {
        return $usuario->can('productos.gestionar');
    }
}
