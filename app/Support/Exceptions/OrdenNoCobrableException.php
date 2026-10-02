<?php

namespace App\Support\Exceptions;

/**
 * M12 · La orden no admite cobro: no está `abierta` (ya pagada o anulada) o no tiene
 * saldo pendiente. Solo una orden `abierta` con saldo > 0 se cobra.
 */
class OrdenNoCobrableException extends DomainException
{
    public function __construct(string $message = 'La orden no admite cobro en su estado actual.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 422;
    }
}
