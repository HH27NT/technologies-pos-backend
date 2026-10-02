<?php

namespace App\Domain\Establecimientos\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Usuarios\Services\ProveedorRolesTenant;
use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * M02 · Rescate de plataforma: el super_admin restablece el acceso del ADMIN de un
 * establecimiento bloqueado. Genera una contraseña temporal (el humano no la elige),
 * revoca los tokens vivos del usuario y audita la acción SIN registrar la contraseña.
 *
 * Solo aplica a usuarios con rol admin de ese establecimiento (mínimo privilegio):
 * resetear operadores es tarea del admin del tenant, no de plataforma.
 */
class RestablecerAccesoAdminService
{
    /** Longitud de la temporal: alfanumérica (sin símbolos) para comunicarla sin ambigüedad. */
    private const LONGITUD_TEMPORAL = 14;

    public function __construct(
        private readonly ProveedorRolesTenant $roles,
        private readonly RegistrarAuditoriaService $auditoria,
    ) {}

    /**
     * @return string La contraseña temporal en claro (se muestra una única vez).
     */
    public function restablecer(Establecimiento $establecimiento, Usuario $usuario): string
    {
        $this->asegurarEsAdmin($establecimiento, $usuario);

        return DB::transaction(function () use ($establecimiento, $usuario) {
            $temporal = Str::password(self::LONGITUD_TEMPORAL, letters: true, numbers: true, symbols: false);

            // El cast 'hashed' del modelo hashea (bcrypt) al asignar; nunca se guarda en claro.
            $usuario->password_hash = $temporal;
            $usuario->save();

            // Cierra sesiones activas: los tokens vigentes dejan de servir tras el reset.
            $usuario->tokens()->delete();

            $this->auditoria->registrar(
                accion: 'establecimiento.acceso_restablecido',
                entidad: 'usuarios',
                entidadId: $usuario->id,
                datosDespues: ['id_establecimiento' => $establecimiento->id, 'rol' => 'admin'],
            );

            return $temporal;
        });
    }

    /** El objetivo del rescate debe ser un admin de ESE establecimiento. */
    private function asegurarEsAdmin(Establecimiento $establecimiento, Usuario $usuario): void
    {
        $rolAdmin = $this->roles->obtener('admin', $establecimiento->id);

        if ($usuario->id_rol !== $rolAdmin->id) {
            throw ValidationException::withMessages([
                'id_usuario' => 'Solo se puede restablecer el acceso de un administrador del establecimiento.',
            ]);
        }
    }
}
