<?php

namespace App\Domain\Ordenes;

/**
 * M11 · Estados del renglón de orden. FUENTE ÚNICA del literal (mismo criterio que
 * EstadoOrden). Un renglón `cancelado` (ADMIN directo en S7) no cuenta en el
 * totalizador; el resto de la lógica de renglón opera sobre los `activo`.
 */
enum EstadoItem: string
{
    case Activo = 'activo';
    case Cancelado = 'cancelado';
}
