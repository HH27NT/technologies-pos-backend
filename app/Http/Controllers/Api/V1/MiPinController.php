<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Autorizaciones\Services\GestionarPinService;
use App\Http\Requests\GuardarPinRequest;
use App\Http\Resources\AutorizacionPinResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M14.1 · PIN de autorización propio (self-service). Cada autorizador fija y cambia SU PIN;
 * ni el super admin ni otro admin lo hacen por él. El PIN nunca se devuelve: estos endpoints
 * solo informan si está configurado y desde cuándo.
 *
 * El scope es el establecimiento activo (TenantScope): si un usuario administrara varios,
 * tendría un PIN por cada uno.
 */
class MiPinController extends ApiController
{
    public function show(Request $request, GestionarPinService $service): JsonResponse
    {
        $this->soloUsuarioDeTenant($request);

        $pin = $service->actual($request->user());

        return ApiResponse::exito(
            $pin ? AutorizacionPinResource::make($pin) : AutorizacionPinResource::sinConfigurar()
        );
    }

    public function update(GuardarPinRequest $request, GestionarPinService $service): JsonResponse
    {
        $pin = $service->fijar($request->user(), (string) $request->validated('pin'));

        return ApiResponse::exito(
            AutorizacionPinResource::make($pin),
            'PIN de autorización actualizado.'
        );
    }

    public function destroy(Request $request, GestionarPinService $service): JsonResponse
    {
        $this->soloUsuarioDeTenant($request);

        $service->eliminar($request->user());

        return ApiResponse::exito(
            AutorizacionPinResource::sinConfigurar(),
            'PIN de autorización eliminado.'
        );
    }

    /**
     * El PIN cuelga de la membresía (usuario, establecimiento). El super admin no pertenece a
     * ninguno, así que no tiene PIN que consultar ni borrar.
     */
    private function soloUsuarioDeTenant(Request $request): void
    {
        abort_if($request->user()->esSuperAdmin(), 403);
    }
}
