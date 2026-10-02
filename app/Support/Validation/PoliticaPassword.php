<?php

namespace App\Support\Validation;

use Illuminate\Validation\Rules\Password;

/**
 * Política de contraseñas configurable (P16): longitud mínima desde
 * config('pos.password.min_length'), sin bloqueo por intentos fallidos.
 * Fuente única para todos los Form Requests que validan contraseñas, de modo
 * que cambiar la política no exija tocar cada request.
 */
class PoliticaPassword
{
    public static function regla(): Password
    {
        return Password::min((int) config('pos.password.min_length', 8));
    }
}
