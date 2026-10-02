<?php

namespace App\Support\Exceptions;

/**
 * M10 · Ya existe una sesión de caja `abierta` en el establecimiento.
 * Regla: solo una caja abierta por establecimiento (Sprint 6).
 */
class CajaYaAbiertaException extends DomainException
{
    public function __construct(string $message = 'Ya hay una caja abierta.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 409;
    }
}
