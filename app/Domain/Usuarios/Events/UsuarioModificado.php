<?php

namespace App\Domain\Usuarios\Events;

use App\Models\Usuario;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho consumado: se modificó un usuario (edición, cambio de estado o de rol). §12.
 */
class UsuarioModificado
{
    use Dispatchable;

    public function __construct(
        public readonly Usuario $usuario,
        public readonly array $datosAntes,
        public readonly array $datosDespues,
        public readonly string $accion = 'usuario.modificado',
    ) {}
}
