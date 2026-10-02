<?php

namespace App\Domain\Autorizaciones;

/**
 * M14 · Estado de una solicitud de autorización. Fuente única del literal de
 * `autorizaciones.estado`. Una solicitud resuelta (aprobada/rechazada) es terminal:
 * no cambia de estado (regla global 22, idempotencia de resolución).
 */
enum EstadoAutorizacion: string
{
    case Pendiente = 'pendiente';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';

    public function esResuelta(): bool
    {
        return $this !== self::Pendiente;
    }
}
