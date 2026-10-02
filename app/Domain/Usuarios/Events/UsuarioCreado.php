<?php

namespace App\Domain\Usuarios\Events;

use App\Models\Usuario;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho consumado: se creó un usuario del establecimiento (§12).
 */
class UsuarioCreado
{
    use Dispatchable;

    public function __construct(public readonly Usuario $usuario) {}
}
