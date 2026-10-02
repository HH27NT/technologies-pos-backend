<?php

namespace App\Policies;

use App\Models\Usuario;

/**
 * Autorización de movimientos de inventario (M08 · Arq §9, matriz Fase 7):
 *  - entrada / ajuste (incrementan stock): solo ADMIN (inventario.entrada/ajustar).
 *    El OPERADOR los obtendrá vía flujo de autorización en el Sprint 9.
 *  - merma / rotura / consumo_interno (salidas por pérdida): ADMIN y OPERADOR
 *    (inventario.merma).
 */
class MovimientoInventarioPolicy
{
    public function registrarEntrada(Usuario $usuario): bool
    {
        return $usuario->can('inventario.entrada');
    }

    public function registrarAjuste(Usuario $usuario): bool
    {
        return $usuario->can('inventario.ajustar');
    }

    public function registrarMerma(Usuario $usuario): bool
    {
        return $usuario->can('inventario.merma');
    }
}
