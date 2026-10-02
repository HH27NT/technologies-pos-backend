<?php

namespace App\Domain\Establecimientos\Events;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho consumado: se dio de alta un establecimiento con su ADMIN inicial (§12).
 */
class EstablecimientoCreado
{
    use Dispatchable;

    public function __construct(
        public readonly Establecimiento $establecimiento,
        public readonly Usuario $adminInicial,
    ) {}
}
