<?php

namespace App\Domain\Establecimientos\Services;

use App\Domain\Establecimientos\Events\EstablecimientoCreado;
use App\Domain\Usuarios\CatalogoRoles;
use App\Domain\Usuarios\Services\ProveedorRolesTenant;
use App\Models\ConfiguracionEstablecimiento;
use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Alta atómica de un establecimiento (M02 · Arq §11):
 * establecimiento + configuración por defecto + los roles de CatalogoRoles::TENANT
 * por team (Spatie) + usuario ADMIN inicial + personal adicional opcional (`personal`:
 * gerente/operador/mesero, para no tener que pasar por "Usuarios" uno por uno
 * después). Emite EstablecimientoCreado (auditoría).
 */
class CrearEstablecimientoService
{
    public function __construct(private readonly ProveedorRolesTenant $roles) {}

    /**
     * @param  array  $datos  Campos del establecimiento + 'admin' (nombre, email/username,
     *                        password) + 'personal' opcional (lista de {rol, nombre,
     *                        email/username, password}).
     * @return array{establecimiento: Establecimiento, admin: Usuario, personal: list<Usuario>}
     */
    public function crear(array $datos): array
    {
        return DB::transaction(function () use ($datos) {
            $establecimiento = Establecimiento::create([
                'nombre' => $datos['nombre'],
                'razon_social' => $datos['razon_social'] ?? null,
                'rfc' => $datos['rfc'] ?? null,
                'direccion' => $datos['direccion'] ?? null,
                'telefono' => $datos['telefono'] ?? null,
                'email' => $datos['email'] ?? null,
                'logo_url' => $datos['logo_url'] ?? null,
                'zona_horaria' => $datos['zona_horaria'] ?? 'America/Mexico_City',
                'moneda' => $datos['moneda'] ?? 'MXN',
                'activo' => $datos['activo'] ?? true,
            ]);

            ConfiguracionEstablecimiento::create([
                'id_establecimiento' => $establecimiento->id,
                'nombre_comercial' => $datos['nombre'],
                'impresion_automatica' => false,
                'aplica_impuesto' => false,
            ]);

            // Provisiona TODOS los roles del catálogo para el team (admin incluido, aunque
            // aquí solo se materialicen usuarios para los que vengan en el payload). Se
            // recorre el catálogo (y no una lista literal) para que un rol nuevo llegue solo
            // a los tenants nuevos: `GET /roles` devuelve las filas materializadas, así que
            // un rol sin fila es un rol invisible en la UI.
            $rolesDelTenant = [];

            foreach (CatalogoRoles::nombresTenant() as $nombre) {
                $rolesDelTenant[$nombre] = $this->roles->obtener($nombre, $establecimiento->id);
            }

            $admin = $this->crearUsuarioTenant(
                $establecimiento->id,
                $rolesDelTenant[CatalogoRoles::ROL_ADMIN],
                $datos['admin'],
            );

            // Personal adicional: mismo camino que el admin (no CrearUsuarioService, que
            // lee el tenant del TenantContext de la petición autenticada — aquí el
            // establecimiento es nuevo y quien crea es el super_admin, sin tenant propio).
            $personal = [];
            foreach ($datos['personal'] ?? [] as $datosPersonal) {
                $rol = $rolesDelTenant[$datosPersonal['rol']]
                    ?? throw new \InvalidArgumentException("Rol de personal desconocido: {$datosPersonal['rol']}");

                $personal[] = $this->crearUsuarioTenant($establecimiento->id, $rol, $datosPersonal);
            }

            event(new EstablecimientoCreado($establecimiento, $admin));

            return ['establecimiento' => $establecimiento, 'admin' => $admin, 'personal' => $personal];
        });
    }

    private function crearUsuarioTenant(int $idEstablecimiento, Role $rol, array $datosUsuario): Usuario
    {
        $usuario = new Usuario([
            'nombre' => $datosUsuario['nombre'],
            'email' => $datosUsuario['email'] ?? null,
            'username' => $datosUsuario['username'] ?? null,
            'password_hash' => $datosUsuario['password'],
            'activo' => true,
            'id_rol' => $rol->id,
        ]);
        $usuario->id_establecimiento = $idEstablecimiento;
        $usuario->save();

        $this->roles->enTeam($idEstablecimiento, fn () => $usuario->assignRole($rol));

        return $usuario;
    }
}
