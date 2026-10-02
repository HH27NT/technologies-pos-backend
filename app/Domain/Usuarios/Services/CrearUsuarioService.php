<?php

namespace App\Domain\Usuarios\Services;

use App\Domain\Usuarios\Events\UsuarioCreado;
use App\Models\Usuario;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Alta de un usuario del establecimiento (M04). El tenant lo impone el TenantContext
 * (nunca el cliente). Sincroniza rol Spatie del team + cache usuarios.id_rol.
 */
class CrearUsuarioService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProveedorRolesTenant $roles,
    ) {}

    public function crear(array $datos): Usuario
    {
        return DB::transaction(function () use ($datos) {
            $idEstablecimiento = (int) $this->tenant->id();

            // resolverParaAsignar (y no obtener) para no vaciar los permisos de un rol
            // a medida: obtener() sincroniza contra el catálogo, que no lo conoce.
            $rol = $this->roles->resolverParaAsignar($datos['rol'], $idEstablecimiento);

            $usuario = Usuario::create([
                'nombre' => $datos['nombre'],
                'email' => $datos['email'] ?? null,
                'username' => $datos['username'] ?? null,
                'password_hash' => $datos['password'],
                'activo' => $datos['activo'] ?? true,
                'id_rol' => $rol->id,
            ]);

            $this->roles->enTeam($idEstablecimiento, fn () => $usuario->assignRole($rol));

            event(new UsuarioCreado($usuario));

            return $usuario;
        });
    }
}
