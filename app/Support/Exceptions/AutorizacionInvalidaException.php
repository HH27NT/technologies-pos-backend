<?php

namespace App\Support\Exceptions;

/**
 * M14 · El sujeto de la solicitud no admite la operación en su estado actual (p. ej.
 * solicitar cancelar un ítem ya cancelado, o anular una orden ya pagada/anulada).
 */
class AutorizacionInvalidaException extends DomainException
{
    public function __construct(string $message = 'La operación solicitada no es válida en el estado actual.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 422;
    }
}
