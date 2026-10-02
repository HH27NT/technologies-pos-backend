<?php

namespace App\Support\Exceptions;

/**
 * Se intentó agregar a una receta un insumo que ya forma parte de ella.
 * El par producto+insumo es único (BOM): la cantidad se corrige editando la línea.
 */
class RecetaDuplicadaException extends DomainException
{
    public function __construct(string $message = 'El insumo ya forma parte de la receta de este producto.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 409;
    }
}
