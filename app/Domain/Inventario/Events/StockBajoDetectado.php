<?php

namespace App\Domain\Inventario\Events;

use App\Models\Insumo;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * El stock de un insumo quedó en o por debajo de su mínimo tras un movimiento.
 * Lo consumen las alertas y el reporte de inventario (Sprint 11).
 */
class StockBajoDetectado
{
    use Dispatchable;

    public function __construct(public readonly Insumo $insumo) {}
}
