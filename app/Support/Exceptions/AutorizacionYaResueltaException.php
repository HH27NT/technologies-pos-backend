<?php

namespace App\Support\Exceptions;

/**
 * M14 · Una solicitud de autorización ya resuelta (aprobada/rechazada) no cambia de
 * estado (regla global 22, idempotencia de resolución).
 */
class AutorizacionYaResueltaException extends DomainException
{
    public function __construct(string $message = 'La autorización ya fue resuelta.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 422;
    }
}
