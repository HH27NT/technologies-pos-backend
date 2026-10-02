<?php

namespace App\Domain\Impresion;

/**
 * M13 · Tipo de documento impreso. Fuente única del literal de `tickets.tipo`.
 * La comanda va a cocina/barra (sin precios); el ticket de cobro es el comprobante.
 */
enum TipoTicket: string
{
    case Comanda = 'comanda';
    case Cobro = 'cobro';

    /** Tipos de impresora candidatos, en orden de preferencia (D2). */
    public function tiposImpresora(): array
    {
        return match ($this) {
            self::Comanda => ['cocina', 'barra'],
            self::Cobro => ['ticket', 'admin'],
        };
    }
}
