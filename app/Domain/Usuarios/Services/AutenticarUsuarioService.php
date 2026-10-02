<?php

namespace App\Domain\Usuarios\Services;

use App\Models\Usuario;
use App\Support\Exceptions\CredencialesInvalidasException;
use App\Support\Exceptions\CuentaInactivaException;
use App\Support\Exceptions\EstablecimientoInactivoException;
use Illuminate\Support\Facades\Hash;

/**
 * Autenticación por Sanctum (M01). Resuelve usuario + tenant + rol a partir de
 * email/username + contraseña, sin requerir selector de establecimiento.
 *
 * Nota de diseño (Sprint 1): el login es global por credencial. Como email/username
 * son únicos POR establecimiento, en teoría una misma credencial podría existir en
 * dos tenants; se valida la contraseña contra cada coincidencia y se resuelve la que
 * verifica. Para el MVP se asume credencial efectivamente única.
 */
class AutenticarUsuarioService
{
    /**
     * @return array{usuario: Usuario, token: string}
     */
    public function login(string $login, string $password): array
    {
        $usuario = Usuario::where('email', $login)
            ->orWhere('username', $login)
            ->get()
            ->first(fn (Usuario $u) => Hash::check($password, $u->password_hash));

        if ($usuario === null) {
            throw new CredencialesInvalidasException;
        }

        if (! $usuario->activo) {
            throw new CuentaInactivaException;
        }

        if ($usuario->id_establecimiento !== null && ! $usuario->establecimiento?->activo) {
            throw new EstablecimientoInactivoException;
        }

        $token = $usuario->createToken('api')->plainTextToken;

        return ['usuario' => $usuario, 'token' => $token];
    }
}
