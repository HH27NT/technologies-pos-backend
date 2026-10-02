<?php

namespace App\Support\Exceptions;

/**
 * M11 · Se intentó agregar a la orden un producto marcado como no disponible en
 * venta (productos.disponible = false).
 */
class ProductoNoDisponibleException extends DomainException
{
    public function __construct(string $message = 'El producto no está disponible para la venta.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 422;
    }
}
