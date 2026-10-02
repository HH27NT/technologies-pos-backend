<?php

namespace App\Domain\Caja\Events;

use App\Models\SesionCaja;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho consumado: se abrió una sesión de caja (§12). Se emite dentro de la
 * transacción de apertura. Lo consumen auditoría/reportes (Sprint 11).
 */
class CajaAbierta
{
    use Dispatchable;

    public function __construct(public readonly SesionCaja $sesion) {}
}
