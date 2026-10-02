<?php

namespace App\Domain\Usuarios\Services;

use Illuminate\Support\Facades\Password;

/**
 * Recuperación de contraseña por correo (M01 · CU-01 A1).
 *
 * Usa el password broker de Laravel (configurado sobre el modelo Usuario), que
 * genera y persiste el token en `password_reset_tokens` y dispara la notificación.
 * Solo se emite el enlace a usuarios ACTIVOS (`activo = true`); el resto de la
 * cadena (token expira en config('auth.passwords...expire')).
 *
 * Nota de seguridad: el controller responde SIEMPRE de forma genérica
 * (anti-enumeración), por lo que el status devuelto aquí no se filtra al cliente.
 * Las credenciales son únicas por establecimiento; para el MVP se asume credencial
 * efectivamente única (mismo supuesto que AutenticarUsuarioService).
 */
class RecuperarPasswordService
{
    /**
     * Solicita el envío del enlace de recuperación.
     *
     * @return string Uno de los Password::* status (no se expone al cliente).
     */
    public function enviarEnlace(string $email): string
    {
        // 'activo' => true se traduce en un WHERE: un usuario desactivado no
        // recibe enlace. Sin contexto de tenant (ruta pública), el TenantScope
        // queda exento; los soft-deleted se excluyen por su scope.
        return Password::broker()->sendResetLink([
            'email' => $email,
            'activo' => true,
        ]);
    }
}
