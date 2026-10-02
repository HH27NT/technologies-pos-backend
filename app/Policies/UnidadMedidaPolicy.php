<?php

namespace App\Policies;

use App\Models\UnidadMedida;
use App\Models\Usuario;

/**
 * Autorización de unidades de medida (M08 · Arq §9, P4): ADMIN del establecimiento.
 * Las unidades GLOBALES son de solo lectura: no se pueden editar ni eliminar
 * (regla reforzada también en el servicio). El aislamiento lo da IncluyeGlobales.
 */
class UnidadMedidaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->can('unidades.gestionar');
    }

    public function view(Usuario $usuario, UnidadMedida $unidad): bool
    {
        return $usuario->can('unidades.gestionar');
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->can('unidades.gestionar');
    }

    public function update(Usuario $usuario, UnidadMedida $unidad): bool
    {
        return $usuario->can('unidades.gestionar') && ! $unidad->esGlobal();
    }

    public function delete(Usuario $usuario, UnidadMedida $unidad): bool
    {
        return $usuario->can('unidades.gestionar') && ! $unidad->esGlobal();
    }
}
