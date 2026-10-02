<?php

namespace App\Support\Exceptions;

/**
 * M11 · La orden no admite el cambio solicitado: o no está `abierta` (pagada/anulada),
 * o el renglón ya fue enviado a comanda / está cancelado. Solo el estado `abierta` y
 * los renglones con enviado=false son modificables.
 */
class OrdenNoModificableException extends DomainException
{
    public function __construct(string $message = 'La orden no admite modificaciones en su estado actual.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 422;
    }
}
