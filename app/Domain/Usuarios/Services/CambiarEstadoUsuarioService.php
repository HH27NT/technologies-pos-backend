<?php

namespace App\Domain\Usuarios\Services;

use App\Domain\Usuarios\Events\UsuarioModificado;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Activa/desactiva un usuario (M04). Antes de desactivar verifica la regla del último
 * admin activo y revoca sus tokens (compensación de P18).
 */
class CambiarEstadoUsuarioService
{
    public function __construct(private readonly UltimoAdminGuard $guard) {}

    public function cambiar(Usuario $usuario, bool $activo): Usuario
    {
        return DB::transaction(function () use ($usuario, $activo) {
            if (! $activo) {
                $this->guard->asegurarNoEsUltimoAdmin($usuario);
            }

            $antes = $usuario->activo;
            $usuario->activo = $activo;
            $usuario->save();

            if (! $activo) {
                $usuario->tokens()->delete();
            }

            event(new UsuarioModificado(
                $usuario,
                ['activo' => $antes],
                ['activo' => $activo],
                $activo ? 'usuario.activado' : 'usuario.desactivado',
            ));

            return $usuario;
        });
    }
}
