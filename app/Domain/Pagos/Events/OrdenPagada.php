<?php

namespace App\Domain\Pagos\Events;

use App\Models\Orden;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho consumado: la orden quedó saldada y pasó a `pagada` (§12). Se emite dentro de
 * la transacción del cobro y DISPARA el descuento de inventario (P1, listener síncrono
 * DescontarInventario). La impresión del ticket lo consumirá S10; el arqueo/reportes,
 * S11. La mesa se libera por derivación (regla global 11).
 */
class OrdenPagada
{
    use Dispatchable;

    public function __construct(public readonly Orden $orden) {}
}
