<?php

namespace App\Support\Exceptions;

/**
 * Se intentó editar o eliminar una unidad de medida GLOBAL (predefinida, P4).
 * Las globales son de solo lectura para el establecimiento; solo puede gestionar
 * sus propias unidades.
 */
class UnidadGlobalNoEditableException extends DomainException
{
    public function __construct(string $message = 'Las unidades de medida predefinidas no se pueden editar ni eliminar.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 422;
    }
}
