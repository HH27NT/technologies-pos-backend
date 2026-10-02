<?php

namespace App\Domain\Usuarios\Events;

use App\Models\Rol;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho consumado: se creó, modificó o eliminó un rol a medida del tenant (§12).
 *
 * Cambiar un rol cambia quién puede hacer qué, así que se audita con el mismo detalle
 * que un movimiento de dinero: la instantánea de permisos antes y después permite
 * responder "¿desde cuándo el cajero podía aplicar descuentos?".
 */
class RolModificado
{
    use Dispatchable;

    public function __construct(
        public readonly Rol $rol,
        public readonly array $datosAntes,
        public readonly array $datosDespues,
        public readonly string $accion = 'rol.modificado',
    ) {}
}
