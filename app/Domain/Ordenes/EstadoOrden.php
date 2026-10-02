<?php

namespace App\Domain\Ordenes;

/**
 * M11 · Estados del ciclo de vida de la orden. FUENTE ÚNICA del literal de estado
 * (mismo criterio que TipoMovimiento del S5 y la recomendación de EstadoCaja del
 * review del S6): servicios, policy y factory derivan de aquí, de modo que el
 * literal no se repite como string crudo.
 *
 * `abierta` admite ítems/descuento/comanda; `pagada` (S8) y `anulada` (S7, ADMIN)
 * son terminales: una orden cerrada ya no se modifica.
 */
enum EstadoOrden: string
{
    case Abierta = 'abierta';
    case Pagada = 'pagada';
    case Anulada = 'anulada';

    /** Solo una orden `abierta` acepta ítems, descuento o comanda. */
    public function esModificable(): bool
    {
        return $this === self::Abierta;
    }
}
