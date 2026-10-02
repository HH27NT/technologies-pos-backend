<?php

namespace App\Support\Exceptions;

/**
 * M14.1 · Otro usuario del mismo establecimiento ya tiene ese PIN. Como el operador teclea
 * SOLO el PIN, este debe ser único dentro del tenant para resolver una identidad sin
 * ambigüedad. No se revela de quién es.
 */
class PinEnUsoException extends DomainException
{
    protected $message = 'Ese PIN ya está en uso en este establecimiento. Elige otro.';

    public function statusHttp(): int
    {
        return 422;
    }
}
