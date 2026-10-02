<?php

namespace Tests\Support;

use App\Domain\Establecimientos\Services\CrearEstablecimientoService;
use App\Domain\Usuarios\Services\CrearUsuarioService;
use App\Models\Establecimiento;
use App\Models\Usuario;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Utilidades de pruebas para crear tenants y usuarios de prueba a través de los
 * servicios de dominio reales (no fixtures crudos), respetando roles/permisos.
 */
trait InteractuaConTenants
{
    /** @return array{establecimiento: Establecimiento, admin: Usuario} */
    protected function nuevoTenant(string $sufijo = ''): array
    {
        $sufijo = $sufijo !== '' ? $sufijo : Str::lower(Str::random(6));

        return app(CrearEstablecimientoService::class)->crear([
            'nombre' => 'Bar '.$sufijo,
            'admin' => [
                'nombre' => 'Admin '.$sufijo,
                'email' => "admin.$sufijo@test.local",
                'password' => 'password123',
            ],
        ]);
    }

    protected function crearUsuarioEnTenant(int $idEstablecimiento, string $rol = 'operador', array $datos = []): Usuario
    {
        $tenant = app(TenantContext::class);
        $permisos = app(PermissionRegistrar::class);

        $tenant->set($idEstablecimiento);
        $permisos->setPermissionsTeamId($idEstablecimiento);

        $usuario = app(CrearUsuarioService::class)->crear(array_merge([
            'nombre' => 'Usuario '.Str::random(4),
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => 'password123',
            'rol' => $rol,
        ], $datos));

        $tenant->olvidar();
        $permisos->setPermissionsTeamId(null);

        return $usuario;
    }

    protected function superAdmin(): Usuario
    {
        return Usuario::where('email', 'super@pos.local')->firstOrFail();
    }
}
