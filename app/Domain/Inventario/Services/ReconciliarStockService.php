<?php

namespace App\Domain\Inventario\Services;

use App\Domain\Inventario\TipoMovimiento;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use Illuminate\Support\Facades\DB;

/**
 * M08 · Recalcula insumos.stock_actual (cache) a partir del ledger
 * movimientos_inventario (fuente de verdad). Salvaguarda de integridad: el cache
 * y la suma firmada del ledger deben coincidir siempre.
 *
 * El signo por tipo lo dicta TipoMovimiento (fuente única, R3): suman los de
 * TipoMovimiento::queSuman() (entrada, ajuste) y el resto resta, de modo que no
 * puede divergir del signo que aplica RegistrarMovimientoService.
 */
class ReconciliarStockService
{
    /** Recalcula y persiste el stock de un insumo; devuelve el stock reconciliado. */
    public function reconciliar(Insumo $insumo): float
    {
        return DB::transaction(function () use ($insumo) {
            $stock = $this->calcularDesdeLedger($insumo->id);

            $insumo->stock_actual = $stock;
            $insumo->save();

            return $stock;
        });
    }

    /** Suma firmada del ledger para un insumo (sin tocar el cache). */
    public function calcularDesdeLedger(int $idInsumo): float
    {
        $suman = MovimientoInventario::where('id_insumo', $idInsumo)
            ->whereIn('tipo', TipoMovimiento::queSuman())
            ->sum('cantidad');

        $restan = MovimientoInventario::where('id_insumo', $idInsumo)
            ->whereNotIn('tipo', TipoMovimiento::queSuman())
            ->sum('cantidad');

        return (float) $suman - (float) $restan;
    }
}
