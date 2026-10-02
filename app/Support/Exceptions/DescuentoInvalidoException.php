<?php

namespace App\Support\Exceptions;

/**
 * M11 · El descuento de orden excede el subtotal de los renglones activos. El
 * descuento (importe) no puede dejar la base gravable en negativo (P10 / D1).
 */
class DescuentoInvalidoException extends DomainException
{
    public function __construct(string $message = 'El descuento no puede exceder el subtotal de la orden.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 422;
    }
}
