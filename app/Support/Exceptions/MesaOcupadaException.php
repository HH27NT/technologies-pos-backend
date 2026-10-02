<?php

namespace App\Support\Exceptions;

/**
 * M11 · La mesa ya tiene una orden `abierta` (regla "una sola orden abierta por
 * mesa"). En PostgreSQL el índice único parcial es la última salvaguarda; el
 * servicio la detecta bajo bloqueo pesimista antes de crear la segunda orden.
 */
class MesaOcupadaException extends DomainException
{
    public function __construct(string $message = 'La mesa ya tiene una orden abierta.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 409;
    }
}
