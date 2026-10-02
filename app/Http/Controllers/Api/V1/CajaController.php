<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Caja\Services\AbrirCajaService;
use App\Domain\Caja\Services\CerrarCajaService;
use App\Http\Requests\AbrirCajaRequest;
use App\Http\Requests\CerrarCajaRequest;
use App\Http\Resources\SesionCajaResource;
use App\Models\SesionCaja;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M10 · Caja (SesionCajaPolicy). ADMIN y OPERADOR abren/cierran y consultan; la caja
 * es del establecimiento (compartida por el turno). El aislamiento por tenant lo
 * garantiza el TenantScope.
 */
class CajaController extends ApiController
{
    public function abrir(AbrirCajaRequest $request, AbrirCajaService $service): JsonResponse
    {
        $this->authorize('abrir', SesionCaja::class);

        $sesion = $service->abrir($request->validated());

        return ApiResponse::creado(
            SesionCajaResource::make($sesion->load('usuarioApertura')),
            'Caja abierta correctamente.'
        );
    }

    public function cerrar(CerrarCajaRequest $request, CerrarCajaService $service): JsonResponse
    {
        $this->authorize('cerrar', SesionCaja::class);

        $sesion = SesionCaja::where('estado', 'abierta')->firstOrFail();
        $sesion = $service->cerrar($sesion, $request->validated());

        return ApiResponse::exito(
            SesionCajaResource::make($sesion->load(['usuarioApertura', 'usuarioCierre'])),
            'Caja cerrada correctamente.'
        );
    }

    /**
     * Sesión abierta actual, o data:null si no hay caja abierta (el front consulta el
     * estado para la compuerta del POS). Quien solo tiene `caja.ver` (mesero) recibe una
     * forma reducida SIN montos: solo necesita saber si el turno tiene caja abierta.
     */
    public function actual(Request $request): JsonResponse
    {
        $this->authorize('verActual', SesionCaja::class);

        $puedeOperar = $request->user()->canAny(['caja.abrir', 'caja.cerrar']);

        $sesion = SesionCaja::when($puedeOperar, fn ($q) => $q->with('usuarioApertura'))
            ->where('estado', 'abierta')
            ->first();

        if ($sesion === null) {
            return ApiResponse::exito(null);
        }

        // Sin permiso para operar caja: solo el estado, nunca el efectivo en cajón.
        if (! $puedeOperar) {
            return ApiResponse::exito([
                'id' => $sesion->id,
                'estado' => $sesion->estado,
                'abierta_at' => $sesion->abierta_at,
            ]);
        }

        return ApiResponse::exito(SesionCajaResource::make($sesion));
    }

    public function historico(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SesionCaja::class);

        $paginador = SesionCaja::with(['usuarioApertura', 'usuarioCierre'])
            ->orderByDesc('abierta_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, SesionCajaResource::class);
    }
}
