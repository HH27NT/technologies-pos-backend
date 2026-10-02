<?php

namespace App\Support\Exceptions;

/**
 * M10 · El cierre con diferencia (faltante/sobrante) exige motivo (P5).
 * La diferencia se calcula en servidor (monto_contado − monto_sistema), por eso
 * la obligatoriedad del motivo se valida en el servicio, no en el Form Request.
 */
class MotivoDiferenciaRequeridoException extends DomainException
{
    public function __construct(string $message = 'El motivo es obligatorio cuando hay diferencia en el arqueo.')
    {
        parent::__construct($message);
    }

    public function statusHttp(): int
    {
        return 422;
    }
}
