<?php

namespace App\Domain\Inventario\Events;

use App\Models\MovimientoInventario;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho consumado: se registró un movimiento en el ledger de inventario (§12).
 * Se emite dentro de la transacción del movimiento.
 */
class MovimientoRegistrado
{
    use Dispatchable;

    public function __construct(public readonly MovimientoInventario $movimiento) {}
}
