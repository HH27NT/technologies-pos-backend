<?php

namespace App\Policies;

use App\Models\Mesa;
use App\Models\Usuario;

/**
 * Autorización del catálogo de mesas (M09 · Arq §9).
 * LEER (viewAny/view) exige `mesas.ver` O `mesas.gestionar`: el POS necesita listar
 * las mesas para elegir dónde abrir la orden (operador/mesero tienen `mesas.ver`).
 * GESTIONAR (crear/editar/estado) sigue siendo solo `mesas.gestionar` (admin/gerente).
 * El aislamiento por tenant lo garantiza el TenantScope (otro tenant → 404).
 */
class MesaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->canAny(['mesas.ver', 'mesas.gestionar']);
    }

    public function view(Usuario $usuario, Mesa $mesa): bool
    {
        return $usuario->canAny(['mesas.ver', 'mesas.gestionar']);
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->can('mesas.gestionar');
    }

    public function update(Usuario $usuario, Mesa $mesa): bool
    {
        return $usuario->can('mesas.gestionar');
    }

    public function cambiarEstado(Usuario $usuario, Mesa $mesa): bool
    {
        return $usuario->can('mesas.gestionar');
    }
}
