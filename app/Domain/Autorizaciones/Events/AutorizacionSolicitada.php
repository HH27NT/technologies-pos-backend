<?php

namespace App\Domain\Autorizaciones\Events;

use App\Models\Autorizacion;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * M14 · El operador solicitó una operación sensible; la autorización quedó `pendiente`
 * en la bandeja del admin. Se emite dentro de la transacción de la solicitud (§15).
 */
class AutorizacionSolicitada
{
    use Dispatchable;

    public function __construct(public readonly Autorizacion $autorizacion) {}
}
