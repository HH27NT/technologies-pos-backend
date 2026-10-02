<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogo\Services\GuardarCategoriaService;
use App\Http\Requests\GuardarCategoriaRequest;
use App\Http\Resources\CategoriaProductoResource;
use App\Models\CategoriaProducto;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M05 · Catálogo de categorías de producto (ADMIN del tenant, vía CategoriaProductoPolicy).
 * El aislamiento por tenant lo garantiza el TenantScope: id de otro tenant → 404.
 */
class CategoriaController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CategoriaProducto::class);

        $paginador = CategoriaProducto::query()
            ->orderBy('orden_display')->orderBy('nombre')
            ->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, CategoriaProductoResource::class);
    }

    public function store(GuardarCategoriaRequest $request, GuardarCategoriaService $service): JsonResponse
    {
        $this->authorize('create', CategoriaProducto::class);

        $categoria = $service->crear($request->validated());

        return ApiResponse::creado(CategoriaProductoResource::make($categoria), 'Categoría creada correctamente.');
    }

    public function show(int $id): JsonResponse
    {
        $categoria = CategoriaProducto::findOrFail($id);
        $this->authorize('view', $categoria);

        return ApiResponse::exito(CategoriaProductoResource::make($categoria));
    }

    public function update(GuardarCategoriaRequest $request, int $id, GuardarCategoriaService $service): JsonResponse
    {
        $categoria = CategoriaProducto::findOrFail($id);
        $this->authorize('update', $categoria);

        $service->actualizar($categoria, $request->validated());

        return ApiResponse::exito(CategoriaProductoResource::make($categoria), 'Categoría actualizada.');
    }

    public function activar(Request $request, int $id, GuardarCategoriaService $service): JsonResponse
    {
        $categoria = CategoriaProducto::findOrFail($id);
        $this->authorize('cambiarEstado', $categoria);

        $activo = $request->has('activo') ? $request->boolean('activo') : ! $categoria->activo;
        $service->cambiarEstado($categoria, $activo);

        // Desactivar no bloquea aunque tenga productos: solo se advierte (DoD Sprint 4).
        $mensaje = $activo ? 'Categoría activada.' : 'Categoría desactivada.';
        if (! $activo && $categoria->productos()->exists()) {
            $mensaje = 'Categoría desactivada. Tiene productos asociados que dejarán de mostrarse en venta.';
        }

        return ApiResponse::exito(CategoriaProductoResource::make($categoria), $mensaje);
    }
}
