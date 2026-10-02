<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventario\Services\GuardarInsumoService;
use App\Http\Requests\GuardarInsumoRequest;
use App\Http\Resources\InsumoResource;
use App\Http\Resources\MovimientoInventarioResource;
use App\Models\Insumo;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M08 · Insumos (ADMIN del tenant, vía InsumoPolicy).
 * stock_actual es de solo lectura aquí: solo cambia por movimientos (D2).
 * El aislamiento por tenant lo garantiza el TenantScope: id de otro tenant → 404.
 */
class InsumoController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Insumo::class);

        $paginador = Insumo::query()
            ->when($request->boolean('stock_bajo'), fn ($q) => $q->whereColumn('stock_actual', '<=', 'stock_minimo'))
            // El armador de recetas pide ?tipo=controlado: los de consumo se echan al
            // tanteo y no deben ni aparecer, para que nadie invente una cantidad.
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', (string) $request->string('tipo')))
            // El armador de recetas y la lista pueden buscar por nombre: un almacén con
            // más de una página de insumos dejaba fuera al resto sin avisar. Solo
            // `nombre` porque el insumo no tiene SKU.
            ->when($request->filled('buscar'), fn ($q) => $this->buscarEn($q, (string) $request->string('buscar'), ['nombre']))
            ->with(['unidadMedida', 'proveedor'])
            ->orderBy('nombre')
            ->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, InsumoResource::class);
    }

    public function store(GuardarInsumoRequest $request, GuardarInsumoService $service): JsonResponse
    {
        $this->authorize('create', Insumo::class);

        $insumo = $service->crear($request->validated());

        return ApiResponse::creado(
            InsumoResource::make($insumo->load(['unidadMedida', 'proveedor'])),
            'Insumo creado correctamente.'
        );
    }

    public function show(int $id): JsonResponse
    {
        $insumo = Insumo::with(['unidadMedida', 'proveedor'])->findOrFail($id);
        $this->authorize('view', $insumo);

        return ApiResponse::exito(InsumoResource::make($insumo));
    }

    public function update(GuardarInsumoRequest $request, int $id, GuardarInsumoService $service): JsonResponse
    {
        $insumo = Insumo::findOrFail($id);
        $this->authorize('update', $insumo);

        $service->actualizar($insumo, $request->validated());

        return ApiResponse::exito(
            InsumoResource::make($insumo->load(['unidadMedida', 'proveedor'])),
            'Insumo actualizado.'
        );
    }

    public function activar(Request $request, int $id, GuardarInsumoService $service): JsonResponse
    {
        $insumo = Insumo::findOrFail($id);
        $this->authorize('cambiarEstado', $insumo);

        $activo = $request->has('activo') ? $request->boolean('activo') : ! $insumo->activo;
        $service->cambiarEstado($insumo, $activo);

        return ApiResponse::exito(
            InsumoResource::make($insumo->load(['unidadMedida', 'proveedor'])),
            $activo ? 'Insumo activado.' : 'Insumo desactivado.'
        );
    }

    /** Kardex: historial de movimientos del insumo (ledger), del más reciente al más antiguo. */
    public function kardex(Request $request, int $id): JsonResponse
    {
        $insumo = Insumo::findOrFail($id);
        $this->authorize('view', $insumo);

        $paginador = $insumo->movimientos()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, MovimientoInventarioResource::class);
    }
}
