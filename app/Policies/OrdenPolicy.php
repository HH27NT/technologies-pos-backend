<?php

namespace App\Policies;

use App\Models\Orden;
use App\Models\Usuario;

/**
 * Autorización de órdenes (M11 · matriz Fase 7). ADMIN y OPERADOR crean, agregan
 * ítems y aplican descuento (sin autorización, P10). Cancelar ítem y anular orden son
 * ADMIN directo en S7 (permiso directo solo del admin; la rama del operador vía
 * solicitud llega en S9). El aislamiento por tenant lo garantiza el TenantScope
 * (orden de otro tenant → 404).
 */
class OrdenPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->can('ordenes.crear');
    }

    public function view(Usuario $usuario, Orden $orden): bool
    {
        return $usuario->can('ordenes.crear');
    }

    public function crear(Usuario $usuario): bool
    {
        return $usuario->can('ordenes.crear');
    }

    /** Agregar/modificar renglón y confirmar comanda comparten el permiso operativo. */
    public function agregarItem(Usuario $usuario, Orden $orden): bool
    {
        return $usuario->can('ordenes.agregar_item');
    }

    public function aplicarDescuento(Usuario $usuario, Orden $orden): bool
    {
        return $usuario->can('ordenes.aplicar_descuento');
    }

    /** Cobrar la orden (M12 · S8). ADMIN y OPERADOR. */
    public function cobrar(Usuario $usuario, Orden $orden): bool
    {
        return $usuario->can('ordenes.cobrar');
    }

    public function cancelarItem(Usuario $usuario, Orden $orden): bool
    {
        return $usuario->can('ordenes.cancelar_item');
    }

    public function anular(Usuario $usuario, Orden $orden): bool
    {
        return $usuario->can('ordenes.anular');
    }

    /**
     * Reasignar el mesero de una orden (traspaso). Acción de gestión (admin/gerente),
     * no del ciclo de venta: no pasa por la compuerta de caja. El operador/mesero no la
     * tienen (no "solicitan" traspaso; es corrección administrativa).
     */
    public function reasignar(Usuario $usuario, Orden $orden): bool
    {
        return $usuario->can('ordenes.reasignar');
    }
}
