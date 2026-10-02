<?php

namespace App\Support\Exceptions;

/**
 * M10 · No hay caja abierta en el establecimiento (regla global 5: no se vende
 * sin caja). La lanza el middleware EnsureCajaAbierta; en el Sprint 6 el middleware
 * se construye y prueba, y se aplica a las rutas de venta en el Sprint 7.
 */
class SinCajaAbiertaException extends DomainException
{
    public function __construct(string $message = 'Abre la caja para poder vender.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 409;
    }
}
