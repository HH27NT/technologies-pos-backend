<?php

namespace App\Domain\Ordenes\Events;

use App\Models\Orden;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho consumado: se anuló una orden abierta (ADMIN directo en S7) (§12). Se emite
 * dentro de la transacción. Lo consumen auditoría/reportes (pérdida registrada /
 * walkout, S11). La mesa se libera por derivación (regla global 11).
 */
class OrdenAnulada
{
    use Dispatchable;

    public function __construct(public readonly Orden $orden) {}
}
