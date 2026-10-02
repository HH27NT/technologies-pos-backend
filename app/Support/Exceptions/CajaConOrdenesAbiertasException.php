<?php

namespace App\Support\Exceptions;

/**
 * M10 · No se puede cerrar la caja con órdenes abiertas en la sesión (F9).
 * La regla queda activa desde el Sprint 6 y será válida sin cambios cuando
 * Órdenes (Sprint 7) genere órdenes reales.
 */
class CajaConOrdenesAbiertasException extends DomainException
{
    public function __construct(string $message = 'No puedes cerrar la caja con órdenes abiertas.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 409;
    }
}
