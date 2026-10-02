<?php

namespace App\Policies;

use App\Models\Proveedor;
use App\Models\Usuario;

/**
 * Autorización de proveedores (M08 · Arq §9): ADMIN del establecimiento.
 * El aislamiento por tenant lo garantiza el TenantScope (otro tenant → 404).
 */
class ProveedorPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->can('proveedores.gestionar');
    }

    public function view(Usuario $usuario, Proveedor $proveedor): bool
    {
        return $usuario->can('proveedores.gestionar');
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->can('proveedores.gestionar');
    }

    public function update(Usuario $usuario, Proveedor $proveedor): bool
    {
        return $usuario->can('proveedores.gestionar');
    }

    public function cambiarEstado(Usuario $usuario, Proveedor $proveedor): bool
    {
        return $usuario->can('proveedores.gestionar');
    }
}
