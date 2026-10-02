<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogo\Services\GuardarProductoService;
use App\Http\Requests\GuardarProductoRequest;
use App\Http\Requests\GuardarProductosLoteRequest;
use App\Http\Resources\ProductoResource;
use App\Models\Producto;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M06 · Catálogo de productos (ADMIN del tenant, vía ProductoPolicy).
 * El aislamiento por tenant lo garantiza el TenantScope: id de otro tenant → 404.
 */
class ProductoController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Producto::class);

        $paginador = Producto::query()
            ->when($request->filled('id_categoria'), fn ($q) => $q->where('id_categoria', $request->integer('id_categoria')))
            ->when($request->filled('buscar'), fn ($q) => $this->buscarEn($q, (string) $request->string('buscar'), ['nombre', 'sku']))
            // Lo pide la pantalla de recetas: una receta sobre un producto que no
            // descuenta inventario no hace nada al venderse, así que no debe ni
            // ofrecerse. `filled` acepta el "0" para poder pedir los que NO descuentan.
            ->when($request->filled('controla_inventario'), fn ($q) => $q->where('controla_inventario', $request->boolean('controla_inventario')))
            // Los que todavía no tienen receta. Es el cierre de la carga del menú:
            // sin esto, saber qué falta por recetear obliga a recorrer el catálogo a
            // ojo, y lo que no se ve no se captura.
            ->when($request->boolean('sin_receta'), fn ($q) => $q->whereDoesntHave('recetas'))
            ->with('categoria')
            // La lista de recetas es una lista de PRODUCTOS: cada uno con los insumos
            // que consume. Traerlos aquí evita la consulta cruzada que antes se hacía
            // contra `/recetas` —paginada por renglón— donde una receta larga se partía
            // entre páginas y el producto de la página siguiente parecía no tener
            // ninguna. La relación solo se carga cuando se pide: el POS no la necesita.
            ->when($request->boolean('con_recetas'), fn ($q) => $q->with('recetas.insumo.unidadMedida'))
            ->orderBy('nombre')
            ->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, ProductoResource::class);
    }

    public function store(GuardarProductoRequest $request, GuardarProductoService $service): JsonResponse
    {
        $this->authorize('create', Producto::class);

        $producto = $service->crear($request->validated());

        return ApiResponse::creado(ProductoResource::make($producto->load('categoria')), 'Producto creado correctamente.');
    }

    /**
     * Alta por lote (carga inicial del menú). Atómica: si una fila no valida, no se
     * crea ninguna y los errores vuelven indexados por posición ("productos.3.nombre"),
     * que es lo que necesita la rejilla para marcar la fila exacta.
     *
     * No se deduplica por nombre: el catálogo no exige nombre único (una "Michelada"
     * puede existir en dos categorías con precios distintos) y decidir qué es repetido
     * es del negocio, no del API. El cliente avisa antes de guardar; la persona audita.
     */
    public function lote(GuardarProductosLoteRequest $request, GuardarProductoService $service): JsonResponse
    {
        $this->authorize('create', Producto::class);

        $filas = $request->validated()['productos'];
        $creados = $service->crearLote($filas);

        return ApiResponse::creado(
            ProductoResource::collection($creados->load('categoria')),
            $creados->count() === 1 ? 'Producto creado correctamente.' : $creados->count().' productos creados correctamente.'
        );
    }

    public function show(int $id): JsonResponse
    {
        $producto = Producto::with('categoria')->findOrFail($id);
        $this->authorize('view', $producto);

        return ApiResponse::exito(ProductoResource::make($producto));
    }

    public function update(GuardarProductoRequest $request, int $id, GuardarProductoService $service): JsonResponse
    {
        $producto = Producto::findOrFail($id);
        $this->authorize('update', $producto);

        $service->actualizar($producto, $request->validated());

        return ApiResponse::exito(ProductoResource::make($producto->load('categoria')), 'Producto actualizado.');
    }

    public function activar(Request $request, int $id, GuardarProductoService $service): JsonResponse
    {
        $producto = Producto::findOrFail($id);
        $this->authorize('cambiarEstado', $producto);

        // En productos, "activar/desactivar" alterna la disponibilidad en venta
        // (la baja lógica es el soft delete, distinto de disponible).
        $disponible = $request->has('disponible') ? $request->boolean('disponible') : ! $producto->disponible;
        $service->cambiarEstado($producto, $disponible);

        return ApiResponse::exito(
            ProductoResource::make($producto->load('categoria')),
            $disponible ? 'Producto disponible.' : 'Producto no disponible.'
        );
    }
}
