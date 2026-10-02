<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Autorizaciones\Services\ResolverAutorizacionService;
use App\Domain\Autorizaciones\Services\SolicitarAutorizacionService;
use App\Http\Requests\RechazarAutorizacionRequest;
use App\Http\Requests\SolicitarAutorizacionRequest;
use App\Http\Resources\AutorizacionResource;
use App\Models\Autorizacion;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M14 · Autorizaciones (flujo de dos niveles, AutorizacionPolicy). El OPERADOR solicita;
 * el ADMIN ve la bandeja, aprueba (ejecuta el servicio destino) o rechaza (sin efectos).
 * El aislamiento por tenant lo garantiza el TenantScope: autorización de otro tenant → 404.
 */
class AutorizacionController extends ApiController
{
    /** Bandeja del admin. Filtrable por estado (default: pendientes). */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Autorizacion::class);

        $paginador = Autorizacion::query()
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->string('tipo')))
            ->when($request->filled('metodo'), fn ($q) => $q->where('metodo', $request->string('metodo')))
            ->with('usuarioSolicita')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, AutorizacionResource::class);
    }

    /** El operador solicita una operación sensible. */
    public function store(SolicitarAutorizacionRequest $request, SolicitarAutorizacionService $service): JsonResponse
    {
        $this->authorize('solicitar', Autorizacion::class);

        $autorizacion = $service->solicitar($request->validated());

        return ApiResponse::creado(
            AutorizacionResource::make($autorizacion),
            'Solicitud de autorización registrada.'
        );
    }

    /** El admin aprueba: el sistema ejecuta el servicio destino y lo enlaza. */
    public function aprobar(int $id, ResolverAutorizacionService $service): JsonResponse
    {
        $autorizacion = Autorizacion::findOrFail($id);
        $this->authorize('aprobar', $autorizacion);

        $autorizacion = $service->aprobar($autorizacion);

        return ApiResponse::exito(
            AutorizacionResource::make($autorizacion->fresh()),
            'Autorización aprobada y ejecutada.'
        );
    }

    /** El admin rechaza: la operación no se ejecuta. */
    public function rechazar(RechazarAutorizacionRequest $request, int $id, ResolverAutorizacionService $service): JsonResponse
    {
        $autorizacion = Autorizacion::findOrFail($id);
        $this->authorize('rechazar', $autorizacion);

        $autorizacion = $service->rechazar($autorizacion, $request->validated());

        return ApiResponse::exito(
            AutorizacionResource::make($autorizacion->fresh()),
            'Autorización rechazada.'
        );
    }
}
