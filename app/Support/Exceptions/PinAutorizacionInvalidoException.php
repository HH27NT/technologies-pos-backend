<?php

namespace App\Support\Exceptions;

/**
 * M14.1 · Override: el PIN no resuelve a ningún autorizador del tenant, no verifica contra
 * su hash, o el usuario está inactivo. Mensaje genérico y 422 uniforme para los tres casos:
 * distinguirlos permitiría enumerar qué PINs existen (§ Seguridad).
 */
class PinAutorizacionInvalidoException extends DomainException
{
    protected $message = 'PIN de autorización inválido.';

    public function statusHttp(): int
    {
        return 422;
    }
}
