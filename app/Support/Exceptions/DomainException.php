<?php

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * Excepción base de las reglas de ESTADO de negocio (no de forma).
 * Las lanzan los servicios; el handler las traduce a la envoltura JSON (§4.3, §20).
 */
abstract class DomainException extends RuntimeException
{
    /** Código HTTP con el que se responde esta excepción. */
    abstract public function statusHttp(): int;
}
