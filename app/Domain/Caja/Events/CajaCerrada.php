<?php

namespace App\Domain\Caja\Events;

use App\Models\SesionCaja;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho consumado: se cerró una sesión de caja con su arqueo (§12). Se emite
 * dentro de la transacción de cierre. Lo consumen auditoría/reportes (Sprint 11).
 */
class CajaCerrada
{
    use Dispatchable;

    public function __construct(public readonly SesionCaja $sesion) {}
}
