<?php

namespace App\Support\Exceptions;

/**
 * M14 · Override: las credenciales verifican, pero ese usuario NO tiene el permiso para
 * autorizar la operación (p. ej. otro operador). 403 distinto del 422 de credenciales
 * para que el frontend pueda diferenciar "contraseña mala" de "no puede autorizar".
 */
class AutorizadorSinPermisoException extends DomainException
{
    protected $message = 'Ese usuario no puede autorizar esta operación.';

    public function statusHttp(): int
    {
        return 403;
    }
}
