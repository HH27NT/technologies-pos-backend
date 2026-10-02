<?php

namespace App\Support\Exceptions;

/**
 * El PIN no resuelve a ningún mesero del tenant, no verifica contra su hash, o el usuario está
 * inactivo. Mensaje genérico y 422 uniforme para los tres casos: distinguirlos permitiría
 * enumerar qué PINs existen (§ Seguridad, igual que en el PIN de autorización).
 */
class PinMeseroInvalidoException extends DomainException
{
    protected $message = 'PIN inválido.';

    public function statusHttp(): int
    {
        return 422;
    }
}
