<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AuditoriaResource;
use App\Models\Auditoria;
use App\Support\Http\ApiResponse;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M15/M16 · Consulta de la bitácora (AuditoriaPolicy). El ADMIN ve la auditoría de su
 * establecimiento; el SUPER_ADMIN, la global de la plataforma (incluidas las acciones sin
 * establecimiento). `auditoria` no usa TenantScope (id_establecimiento nullable), así que
 * el acotamiento por tenant se hace explícito aquí.
 */
class AuditoriaController extends ApiController
{
    /** Auditoría del establecimiento del admin (paginada, filtrable por acción/entidad). */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Auditoria::class);

        $paginador = $this->filtrar(
            Auditoria::query()->where('id_establecimiento', app(TenantContext::class)->id()),
            $request,
        )->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, AuditoriaResource::class);
    }

    /** Auditoría global de la plataforma (super_admin). */
    public function global(Request $request): JsonResponse
    {
        $this->authorize('global', Auditoria::class);

        $paginador = $this->filtrar(Auditoria::query(), $request)
            ->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, AuditoriaResource::class);
    }

    private function filtrar(Builder $q, Request $request): Builder
    {
        return $q
            ->when($request->filled('accion'), fn ($q) => $q->where('accion', $request->string('accion')))
            ->when($request->filled('entidad'), fn ($q) => $q->where('entidad', $request->string('entidad')))
            ->with('usuario')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
