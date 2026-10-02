<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventario\Services\GuardarProveedorService;
use App\Http\Requests\GuardarProveedorRequest;
use App\Http\Resources\ProveedorResource;
use App\Models\Proveedor;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M08 · Proveedores (ADMIN del tenant, vía ProveedorPolicy).
 * El aislamiento por tenant lo garantiza el TenantScope: id de otro tenant → 404.
 */
class ProveedorController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Proveedor::class);

        $paginador = Proveedor::query()
            ->orderBy('nombre')
            ->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, ProveedorResource::class);
    }

    public function store(GuardarProveedorRequest $request, GuardarProveedorService $service): JsonResponse
    {
        $this->authorize('create', Proveedor::class);

        $proveedor = $service->crear($request->validated());

        return ApiResponse::creado(ProveedorResource::make($proveedor), 'Proveedor creado correctamente.');
    }

    public function show(int $id): JsonResponse
    {
        $proveedor = Proveedor::findOrFail($id);
        $this->authorize('view', $proveedor);

        return ApiResponse::exito(ProveedorResource::make($proveedor));
    }

    public function update(GuardarProveedorRequest $request, int $id, GuardarProveedorService $service): JsonResponse
    {
        $proveedor = Proveedor::findOrFail($id);
        $this->authorize('update', $proveedor);

        $service->actualizar($proveedor, $request->validated());

        return ApiResponse::exito(ProveedorResource::make($proveedor), 'Proveedor actualizado.');
    }

    public function activar(Request $request, int $id, GuardarProveedorService $service): JsonResponse
    {
        $proveedor = Proveedor::findOrFail($id);
        $this->authorize('cambiarEstado', $proveedor);

        $activo = $request->has('activo') ? $request->boolean('activo') : ! $proveedor->activo;
        $service->cambiarEstado($proveedor, $activo);

        return ApiResponse::exito(
            ProveedorResource::make($proveedor),
            $activo ? 'Proveedor activado.' : 'Proveedor desactivado.'
        );
    }
}
