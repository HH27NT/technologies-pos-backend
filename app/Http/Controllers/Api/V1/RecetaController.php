<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventario\Services\GestionarRecetaService;
use App\Http\Requests\GuardarRecetaRequest;
use App\Http\Requests\ReemplazarRecetaRequest;
use App\Http\Resources\RecetaProductoResource;
use App\Models\Producto;
use App\Models\RecetaProducto;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M07 · Recetas (BOM, ADMIN del tenant, vía RecetaProductoPolicy).
 * El aislamiento por tenant lo garantiza el TenantScope: id de otro tenant → 404.
 */
class RecetaController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RecetaProducto::class);

        $paginador = RecetaProducto::query()
            ->when($request->filled('id_producto'), fn ($q) => $q->where('id_producto', $request->integer('id_producto')))
            // `insumo.unidadMedida` no es adorno: el armador pinta la unidad y estima
            // el costo desde aquí. Sin esto tiene que buscar cada insumo en el catálogo
            // que trae aparte, y un insumo fuera de esa página deja el renglón en blanco.
            ->with(['producto', 'insumo.unidadMedida'])
            ->orderBy('id_producto')
            ->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, RecetaProductoResource::class);
    }

    public function store(GuardarRecetaRequest $request, GestionarRecetaService $service): JsonResponse
    {
        $this->authorize('create', RecetaProducto::class);

        $receta = $service->crear($request->validated());

        return ApiResponse::creado(
            RecetaProductoResource::make($receta->load(['producto', 'insumo'])),
            'Receta creada correctamente.'
        );
    }

    /**
     * Receta completa de un producto en una sola operación. La pantalla arma la lista
     * entera —un preparado lleva cuatro insumos— y la manda de golpe: renglón por
     * renglón dejaría un producto a medio recomponer visible para el cobro.
     *
     * `insumos: []` borra la receta. El producto se resuelve con el TenantScope
     * puesto, así que un id de otro establecimiento devuelve 404, no 403.
     */
    public function reemplazarDeProducto(
        ReemplazarRecetaRequest $request,
        int $idProducto,
        GestionarRecetaService $service
    ): JsonResponse {
        $this->authorize('create', RecetaProducto::class);
        $producto = Producto::findOrFail($idProducto);

        $recetas = $service->reemplazar($producto->id, $request->validated()['insumos']);

        return ApiResponse::exito(
            RecetaProductoResource::collection($recetas),
            $recetas->isEmpty()
                ? 'Receta eliminada.'
                : 'Receta guardada con '.$recetas->count().($recetas->count() === 1 ? ' insumo.' : ' insumos.')
        );
    }

    public function show(int $id): JsonResponse
    {
        $receta = RecetaProducto::with(['producto', 'insumo'])->findOrFail($id);
        $this->authorize('view', $receta);

        return ApiResponse::exito(RecetaProductoResource::make($receta));
    }

    public function update(GuardarRecetaRequest $request, int $id, GestionarRecetaService $service): JsonResponse
    {
        $receta = RecetaProducto::findOrFail($id);
        $this->authorize('update', $receta);

        $service->actualizar($receta, $request->validated());

        return ApiResponse::exito(
            RecetaProductoResource::make($receta->load(['producto', 'insumo'])),
            'Receta actualizada.'
        );
    }

    public function destroy(int $id, GestionarRecetaService $service): JsonResponse
    {
        $receta = RecetaProducto::findOrFail($id);
        $this->authorize('delete', $receta);

        $service->eliminar($receta);

        return ApiResponse::exito(null, 'Receta eliminada.');
    }
}
