<?php

namespace App\Domain\Ordenes\Events;

use App\Models\Orden;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho consumado: se abrió una orden (§12). Se emite dentro de la transacción de
 * creación. Lo consumen auditoría/reportes (S11) e impresión de comanda (S10).
 */
class OrdenCreada
{
    use Dispatchable;

    public function __construct(public readonly Orden $orden) {}
}
