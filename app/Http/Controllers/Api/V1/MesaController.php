<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogo\Services\GuardarMesaService;
use App\Http\Requests\GuardarMesaRequest;
use App\Http\Resources\MesaResource;
use App\Models\Mesa;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M09 · Catálogo de mesas (ADMIN del tenant, vía MesaPolicy).
 * El aislamiento por tenant lo garantiza el TenantScope: id de otro tenant → 404.
 */
class MesaController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Mesa::class);

        $paginador = Mesa::query()
            ->with('ordenAbierta')
            ->orderBy('numero')
            ->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, MesaResource::class);
    }

    public function store(GuardarMesaRequest $request, GuardarMesaService $service): JsonResponse
    {
        $this->authorize('create', Mesa::class);

        $mesa = $service->crear($request->validated());

        return ApiResponse::creado(MesaResource::make($mesa), 'Mesa creada correctamente.');
    }

    public function show(int $id): JsonResponse
    {
        $mesa = Mesa::with('ordenAbierta')->findOrFail($id);
        $this->authorize('view', $mesa);

        return ApiResponse::exito(MesaResource::make($mesa));
    }

    public function update(GuardarMesaRequest $request, int $id, GuardarMesaService $service): JsonResponse
    {
        $mesa = Mesa::findOrFail($id);
        $this->authorize('update', $mesa);

        $service->actualizar($mesa, $request->validated());

        return ApiResponse::exito(MesaResource::make($mesa), 'Mesa actualizada.');
    }

    public function activar(Request $request, int $id, GuardarMesaService $service): JsonResponse
    {
        $mesa = Mesa::findOrFail($id);
        $this->authorize('cambiarEstado', $mesa);

        $activa = $request->has('activa') ? $request->boolean('activa') : ! $mesa->activa;
        $service->cambiarEstado($mesa, $activa);

        return ApiResponse::exito(
            MesaResource::make($mesa),
            $activa ? 'Mesa activada.' : 'Mesa desactivada.'
        );
    }
}
