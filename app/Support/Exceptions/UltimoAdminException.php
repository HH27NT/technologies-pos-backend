<?php

namespace App\Support\Exceptions;

/**
 * Se intentó dejar al establecimiento sin ningún administrador activo
 * (desactivar o degradar de rol al último admin). M04 · Fase 4.
 */
class UltimoAdminException extends DomainException
{
    protected $message = 'Debe existir al menos un administrador activo.';

    public function statusHttp(): int
    {
        return 409;
    }
}
