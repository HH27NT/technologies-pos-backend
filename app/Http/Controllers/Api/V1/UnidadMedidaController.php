<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventario\Services\GuardarUnidadMedidaService;
use App\Http\Requests\GuardarUnidadMedidaRequest;
use App\Http\Resources\UnidadMedidaResource;
use App\Models\UnidadMedida;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M08 · Unidades de medida (ADMIN del tenant, vía UnidadMedidaPolicy, P4).
 * El listado devuelve globales + propias (IncluyeGlobales); solo se pueden
 * editar/eliminar las propias (policy + servicio).
 */
class UnidadMedidaController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UnidadMedida::class);

        $paginador = UnidadMedida::query()
            ->orderBy('nombre')
            ->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, UnidadMedidaResource::class);
    }

    public function store(GuardarUnidadMedidaRequest $request, GuardarUnidadMedidaService $service): JsonResponse
    {
        $this->authorize('create', UnidadMedida::class);

        $unidad = $service->crear($request->validated());

        return ApiResponse::creado(UnidadMedidaResource::make($unidad), 'Unidad de medida creada correctamente.');
    }

    public function show(int $id): JsonResponse
    {
        $unidad = UnidadMedida::findOrFail($id);
        $this->authorize('view', $unidad);

        return ApiResponse::exito(UnidadMedidaResource::make($unidad));
    }

    public function update(GuardarUnidadMedidaRequest $request, int $id, GuardarUnidadMedidaService $service): JsonResponse
    {
        $unidad = UnidadMedida::findOrFail($id);
        $this->authorize('update', $unidad);

        $service->actualizar($unidad, $request->validated());

        return ApiResponse::exito(UnidadMedidaResource::make($unidad), 'Unidad de medida actualizada.');
    }

    public function destroy(int $id, GuardarUnidadMedidaService $service): JsonResponse
    {
        $unidad = UnidadMedida::findOrFail($id);
        $this->authorize('delete', $unidad);

        $service->eliminar($unidad);

        return ApiResponse::exito(null, 'Unidad de medida eliminada.');
    }
}
