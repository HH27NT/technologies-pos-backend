<?php

namespace App\Support\Exceptions;

/**
 * Una salida MANUAL de inventario (merma/rotura/consumo_interno) dejaría el stock
 * en negativo. Se bloquea (D1, Sprint 5): el negativo se reserva a la venta (P2),
 * que no debe frenarse; una salida manual que excede el stock es error de captura.
 */
class StockInsuficienteException extends DomainException
{
    public function __construct(string $message = 'La cantidad excede el stock disponible del insumo.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 422;
    }
}
