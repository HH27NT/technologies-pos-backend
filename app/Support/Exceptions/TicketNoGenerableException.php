<?php

namespace App\Support\Exceptions;

/**
 * M13 · El documento no puede generarse en el estado actual: ticket de cobro sobre una
 * orden no `pagada`, o comanda sin renglones enviados.
 */
class TicketNoGenerableException extends DomainException
{
    public function __construct(string $message = 'El ticket no puede generarse en el estado actual de la orden.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 422;
    }
}
