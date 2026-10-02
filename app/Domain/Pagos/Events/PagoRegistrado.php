<?php

namespace App\Domain\Pagos\Events;

use App\Models\Pago;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho consumado: se asentó un pago (parcial o total) de una orden (§12). Se emite
 * dentro de la transacción del cobro. Lo consumen auditoría/reportes (S11).
 */
class PagoRegistrado
{
    use Dispatchable;

    public function __construct(public readonly Pago $pago) {}
}
