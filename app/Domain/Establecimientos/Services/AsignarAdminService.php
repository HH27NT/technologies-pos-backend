<?php

namespace App\Domain\Establecimientos\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Usuarios\Services\ProveedorRolesTenant;
use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Promueve a ADMIN a un usuario existente del establecimiento (M02 · asignar-admin).
 * Sincroniza el rol Spatie del team y el cache usuarios.id_rol.
 */
class AsignarAdminService
{
    public function __construct(
        private readonly ProveedorRolesTenant $roles,
        private readonly RegistrarAuditoriaService $auditoria,
    ) {}

    public function asignar(Establecimiento $establecimiento, Usuario $usuario): Usuario
    {
        return DB::transaction(function () use ($establecimiento, $usuario) {
            $rolAdmin = $this->roles->obtener('admin', $establecimiento->id);

            $this->roles->enTeam($establecimiento->id, function () use ($usuario, $rolAdmin) {
                $usuario->syncRoles([$rolAdmin]);
            });

            $usuario->id_rol = $rolAdmin->id;
            $usuario->save();

            $this->auditoria->registrar(
                accion: 'establecimiento.admin_asignado',
                entidad: 'usuarios',
                entidadId: $usuario->id,
                datosDespues: ['id_establecimiento' => $establecimiento->id, 'rol' => 'admin'],
            );

            return $usuario;
        });
    }
}
