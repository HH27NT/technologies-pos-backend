<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Establecimientos\Services\ActualizarConfiguracionService;
use App\Http\Requests\ActualizarConfiguracionRequest;
use App\Http\Resources\ConfiguracionEstablecimientoResource;
use App\Models\ConfiguracionEstablecimiento;
use App\Support\Http\ApiResponse;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * M03 · Configuración 1:1 del establecimiento (ADMIN del tenant, vía
 * ConfiguracionEstablecimientoPolicy). La fila se resuelve siempre por el
 * id_establecimiento del TenantContext, nunca por un id del cliente.
 *
 * El `where` explícito se conserva aunque el modelo ya use `BelongsToTenant`: sin contexto el
 * TenantScope no filtra, y un `firstOrFail()` a secas le devolvería a un super_admin sin tenant
 * la configuración del primer establecimiento que hubiera en la tabla. Filtrando, no hay fila
 * y responde 404, que es lo correcto.
 */
class ConfiguracionController extends ApiController
{
    public function show(TenantContext $tenant): JsonResponse
    {
        $configuracion = $this->configuracionDelTenant($tenant);
        $this->authorize('view', $configuracion);

        return ApiResponse::exito(ConfiguracionEstablecimientoResource::make($configuracion));
    }

    public function update(ActualizarConfiguracionRequest $request, TenantContext $tenant, ActualizarConfiguracionService $service): JsonResponse
    {
        $configuracion = $this->configuracionDelTenant($tenant);
        $this->authorize('update', $configuracion);

        $service->actualizar($configuracion, $request->validated());

        return ApiResponse::exito(
            ConfiguracionEstablecimientoResource::make($configuracion),
            'Configuración actualizada.'
        );
    }

    private function configuracionDelTenant(TenantContext $tenant): ConfiguracionEstablecimiento
    {
        return ConfiguracionEstablecimiento::where('id_establecimiento', $tenant->id())->firstOrFail();
    }
}
