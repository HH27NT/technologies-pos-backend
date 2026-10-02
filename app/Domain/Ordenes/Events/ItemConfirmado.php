<?php

namespace App\Domain\Ordenes\Events;

use App\Models\Orden;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho consumado: se confirmó una comanda (renglones marcados enviado=true) (§12).
 * En S7 solo marca el estado y emite el evento; la impresión física llega en S10
 * (consumidor de este evento). NO toca inventario (P1).
 */
class ItemConfirmado
{
    use Dispatchable;

    public function __construct(public readonly Orden $orden) {}
}
