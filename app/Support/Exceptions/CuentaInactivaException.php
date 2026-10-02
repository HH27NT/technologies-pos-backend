<?php

namespace App\Support\Exceptions;

/**
 * El usuario existe pero está desactivado (activo = false). Fase 4 · M01.
 */
class CuentaInactivaException extends DomainException
{
    protected $message = 'Tu cuenta está desactivada. Contacta al administrador.';

    public function statusHttp(): int
    {
        return 403;
    }
}
