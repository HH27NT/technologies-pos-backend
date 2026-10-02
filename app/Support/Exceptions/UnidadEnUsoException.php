<?php

namespace App\Support\Exceptions;

/**
 * Se intentó eliminar una unidad de medida referenciada por uno o más insumos.
 * La FK es restrictiva: primero hay que reasignar o dar de baja esos insumos.
 */
class UnidadEnUsoException extends DomainException
{
    public function __construct(string $message = 'No se puede eliminar la unidad: hay insumos que la utilizan.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 409;
    }
}
