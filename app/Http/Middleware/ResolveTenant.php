<?php

namespace App\Http\Middleware;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\Establecimiento;
use App\Support\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el TenantContext y el team de Spatie tras autenticar (Arq §7, §10).
 *
 * - Usuario de establecimiento: contexto = su id_establecimiento.
 * - SUPER_ADMIN: sin contexto, salvo impersonación explícita (P17) vía la
 *   cabecera X-Establecimiento-Id, que queda marcada y auditada.
 */
class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionRegistrar $permisos,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario && ! $usuario->esSuperAdmin()) {
            $this->fijar((int) $usuario->id_establecimiento);

            return $next($request);
        }

        // SUPER_ADMIN: solo fija contexto si impersona un establecimiento (P17).
        $idObjetivo = $request->header('X-Establecimiento-Id');

        if ($usuario && $usuario->esSuperAdmin() && $idObjetivo !== null) {
            $establecimiento = Establecimiento::find((int) $idObjetivo);

            if ($establecimiento) {
                $this->fijar($establecimiento->id, impersonando: true);

                app(RegistrarAuditoriaService::class)->registrar(
                    accion: 'soporte.impersonacion',
                    entidad: 'establecimientos',
                    entidadId: $establecimiento->id,
                );
            }
        } else {
            $this->permisos->setPermissionsTeamId(null);
        }

        return $next($request);
    }

    private function fijar(int $idEstablecimiento, bool $impersonando = false): void
    {
        $this->tenant->set($idEstablecimiento, $impersonando);
        $this->permisos->setPermissionsTeamId($idEstablecimiento);
    }
}
