<?php

namespace App\Domain\Usuarios\Services;

use App\Domain\Usuarios\Events\UsuarioModificado;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Edición de datos de un usuario del establecimiento (M04). No cambia rol ni estado
 * (esos tienen sus propios servicios con sus reglas). Re-hashea la contraseña si viene.
 */
class ActualizarUsuarioService
{
    public function actualizar(Usuario $usuario, array $datos): Usuario
    {
        return DB::transaction(function () use ($usuario, $datos) {
            $campos = array_intersect_key($datos, array_flip(['nombre', 'email', 'username']));

            if (! empty($datos['password'])) {
                $campos['password_hash'] = $datos['password'];
            }

            $antes = $usuario->filtrarParaAuditoria($usuario->only(array_keys($campos)));

            $usuario->fill($campos)->save();

            event(new UsuarioModificado(
                $usuario,
                $antes,
                $usuario->filtrarParaAuditoria($usuario->only(array_keys($campos))),
                'usuario.actualizado',
            ));

            return $usuario;
        });
    }
}
