<?php

namespace App\Support\Exceptions;

/**
 * La configuración del establecimiento quedaría en un estado de negocio inválido
 * (p. ej. impuesto activado sin una tasa positiva). M03 · Fase 4.
 */
class ConfiguracionInvalidaException extends DomainException
{
    public function __construct(string $message = 'Si el impuesto está activo, la tasa debe ser mayor que cero.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 422;
    }
}
