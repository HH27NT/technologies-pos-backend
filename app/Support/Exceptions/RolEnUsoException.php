<?php

namespace App\Support\Exceptions;

/**
 * Se intentó eliminar un rol que todavía tiene usuarios asignados.
 *
 * La FK `usuarios.id_rol` ya lo impediría a nivel de base, pero eso saldría como un
 * error 500 de integridad. Esto lo convierte en una regla de negocio explicada.
 */
class RolEnUsoException extends DomainException
{
    protected $message = 'No puedes eliminar un rol que tiene usuarios asignados. Reasígnalos primero.';

    public function statusHttp(): int
    {
        return 409;
    }
}
