<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogo\Services\GuardarImpresoraService;
use App\Http\Requests\GuardarImpresoraRequest;
use App\Http\Resources\ImpresoraResource;
use App\Models\Impresora;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M13 · Catálogo de impresoras (ADMIN del tenant, vía ImpresoraPolicy).
 * El aislamiento por tenant lo garantiza el TenantScope: id de otro tenant → 404.
 */
class ImpresoraController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Impresora::class);

        $paginador = Impresora::query()->orderBy('nombre')->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, ImpresoraResource::class);
    }

    public function store(GuardarImpresoraRequest $request, GuardarImpresoraService $service): JsonResponse
    {
        $this->authorize('create', Impresora::class);

        $impresora = $service->crear($request->validated());

        return ApiResponse::creado(ImpresoraResource::make($impresora), 'Impresora creada correctamente.');
    }

    public function show(int $id): JsonResponse
    {
        $impresora = Impresora::findOrFail($id);
        $this->authorize('view', $impresora);

        return ApiResponse::exito(ImpresoraResource::make($impresora));
    }

    public function update(GuardarImpresoraRequest $request, int $id, GuardarImpresoraService $service): JsonResponse
    {
        $impresora = Impresora::findOrFail($id);
        $this->authorize('update', $impresora);

        $service->actualizar($impresora, $request->validated());

        return ApiResponse::exito(ImpresoraResource::make($impresora), 'Impresora actualizada.');
    }

    public function activar(Request $request, int $id, GuardarImpresoraService $service): JsonResponse
    {
        $impresora = Impresora::findOrFail($id);
        $this->authorize('cambiarEstado', $impresora);

        $activa = $request->has('activa') ? $request->boolean('activa') : ! $impresora->activa;
        $service->cambiarEstado($impresora, $activa);

        return ApiResponse::exito(
            ImpresoraResource::make($impresora),
            $activa ? 'Impresora activada.' : 'Impresora desactivada.'
        );
    }
}
