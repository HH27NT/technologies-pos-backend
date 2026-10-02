<?php

namespace App\Support\Exceptions;

/**
 * Se intentó usar el flujo de PIN de mesero en un establecimiento que no opera con terminal
 * compartida. No es un error del usuario, es una llamada fuera de contexto: en ese modo la
 * cuenta que abre la orden YA es la persona y estampar `id_mesero` solo introduciría ruido.
 */
class TerminalCompartidaInactivaException extends DomainException
{
    protected $message = 'Este establecimiento no opera con terminal compartida.';

    public function statusHttp(): int
    {
        return 422;
    }
}
