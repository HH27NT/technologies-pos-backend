<?php

namespace App\Support\Exceptions;

/**
 * M14 · Override: se superó el límite de intentos (throttle antifuerza-bruta contra la
 * contraseña del admin). 429 con la envoltura JSON estándar.
 */
class DemasiadosIntentosException extends DomainException
{
    public function __construct(private readonly int $segundos = 60)
    {
        parent::__construct('Demasiados intentos de autorización. Inténtalo de nuevo en '.$segundos.' segundos.');
    }

    public function segundos(): int
    {
        return $this->segundos;
    }

    public function statusHttp(): int
    {
        return 429;
    }
}
