<?php

namespace App\Support\Exceptions;

/**
 * El establecimiento del usuario está desactivado (P19 · §20). Bloquea el acceso operativo.
 */
class EstablecimientoInactivoException extends DomainException
{
    protected $message = 'El establecimiento está desactivado.';

    public function statusHttp(): int
    {
        return 423;
    }
}
