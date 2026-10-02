<?php

namespace App\Support\Exceptions;

/**
 * M12 · Sobrepago no permitido. En tarjeta/transferencia el monto no puede exceder el
 * saldo pendiente (solo el efectivo admite sobrepago y devuelve cambio).
 */
class SobrepagoNoPermitidoException extends DomainException
{
    public function __construct(string $message = 'El monto excede el saldo pendiente de la orden.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 422;
    }
}
