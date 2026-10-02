<?php

namespace App\Support\Exceptions;

/**
 * Credenciales de login incorrectas (Fase 4 · M01).
 */
class CredencialesInvalidasException extends DomainException
{
    protected $message = 'Usuario o contraseña incorrectos.';

    public function statusHttp(): int
    {
        return 401;
    }
}
