<?php

namespace App\Domain\Autorizaciones\Events;

use App\Models\Autorizacion;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * M14 · El admin resolvió la solicitud (aprobada → ejecutó el servicio destino; rechazada
 * → sin efectos). Se emite dentro de la transacción de la resolución (§15).
 */
class AutorizacionResuelta
{
    use Dispatchable;

    public function __construct(public readonly Autorizacion $autorizacion) {}
}
