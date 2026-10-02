<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Usuarios\CatalogoPermisos;
use App\Domain\Usuarios\Services\GuardarRolService;
use App\Http\Requests\GuardarRolRequest;
use App\Http\Resources\RolResource;
use App\Models\Rol;
use App\Support\Http\ApiResponse;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * M04 · Roles del establecimiento. Lectura para quien gestiona personal (alimenta el
 * selector de rol) y CRUD del EDITOR DE ROLES A MEDIDA para el admin (`roles.gestionar`).
 *
 * Los presets del catálogo son de SOLO LECTURA (RolPolicy): se resincronizan en cada
 * arranque, así que editarlos se perdería en el siguiente despliegue. Para personalizar
 * se clona (`clonar_de` en el alta).
 *
 * El aislamiento por tenant lo da el TenantScope del modelo Rol: un id de otro
 * establecimiento responde 404.
 */
class RolController extends ApiController
{
    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        $this->authorize('viewAny', Rol::class);

        if (! $tenant->has()) {
            // Super admin: ve los roles PLANTILLA (team nulo), no los de ningún tenant.
            $plantillas = Role::query()->whereNull('id_establecimiento')->with('permissions')->get();

            return ApiResponse::exito(RolResource::collection($plantillas)->resolve($request));
        }

        $roles = Rol::query()
            ->with('permissions')
            ->withCount('usuarios')
            ->orderBy('name')
            ->get();

        return ApiResponse::exito(RolResource::collection($roles)->resolve($request));
    }

    /** Catálogo de permisos otorgables, agrupado por módulo y con texto en español. */
    public function permisos(): JsonResponse
    {
        $this->authorize('create', Rol::class);

        return ApiResponse::exito(CatalogoPermisos::agrupados());
    }

    public function store(GuardarRolRequest $request, GuardarRolService $service): JsonResponse
    {
        $this->authorize('create', Rol::class);

        $datos = $request->validated();

        // Clonar es la vía prevista para personalizar un preset. Se pasa por el mismo
        // endpoint (y no uno aparte) porque el resultado es idéntico: un rol propio nuevo.
        $rol = isset($datos['clonar_de'])
            ? $service->clonar(
                Rol::findOrFail($datos['clonar_de']),
                $datos['etiqueta'],
                $datos['descripcion'] ?? null,
                $datos['permisos'],
            )
            : $service->crear($datos);

        return ApiResponse::creado(RolResource::make($rol->load('permissions')), 'Rol creado correctamente.');
    }

    public function update(GuardarRolRequest $request, int $id, GuardarRolService $service): JsonResponse
    {
        $rol = Rol::findOrFail($id);
        $this->authorize('update', $rol);

        $service->actualizar($rol, $request->validated());

        return ApiResponse::exito(RolResource::make($rol->load('permissions')), 'Rol actualizado.');
    }

    public function destroy(int $id, GuardarRolService $service): JsonResponse
    {
        $rol = Rol::findOrFail($id);
        $this->authorize('delete', $rol);

        $service->eliminar($rol);

        return ApiResponse::exito(null, 'Rol eliminado.');
    }
}
