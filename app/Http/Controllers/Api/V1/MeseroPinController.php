<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ordenes\ModoTerminalCompartida;
use App\Domain\Ordenes\Services\GestionarMeseroPinService;
use App\Domain\Ordenes\Services\ResolverMeseroPorPinService;
use App\Domain\Ordenes\Services\SesionMeseroTerminal;
use App\Http\Requests\FijarMeseroPinRequest;
use App\Http\Requests\IdentificarMeseroRequest;
use App\Http\Resources\MeseroPinResource;
use App\Models\Usuario;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PIN de mesero: gestión (por quien administra el personal) e identificación (desde el POS).
 *
 * Dos audiencias muy distintas en un mismo recurso:
 * - `show`/`update`/`destroy` — el admin da de alta el PIN del personal. Requiere
 *   `usuarios.gestionar` y pasa por `UsuarioPolicy::update`, así que un gerente no puede
 *   fijarle PIN a un admin.
 * - `identificar` — la tablet pregunta quién está tecleando. **No autentica**: no emite token
 *   ni cambia la sesión; solo devuelve a quién atribuir la venta.
 *
 * El PIN nunca se devuelve en ninguna respuesta.
 */
class MeseroPinController extends ApiController
{
    /** ¿Este miembro del personal tiene PIN configurado? (nunca cuál). */
    public function show(int $id, GestionarMeseroPinService $service): JsonResponse
    {
        $usuario = Usuario::findOrFail($id);
        $this->authorize('update', $usuario);

        $pin = $service->actual($usuario);

        return ApiResponse::exito(
            $pin ? MeseroPinResource::make($pin) : MeseroPinResource::sinConfigurar()
        );
    }

    public function update(FijarMeseroPinRequest $request, int $id, GestionarMeseroPinService $service): JsonResponse
    {
        $usuario = Usuario::findOrFail($id);
        $this->authorize('update', $usuario);

        $pin = $service->fijar($usuario, (string) $request->validated('pin'));

        return ApiResponse::exito(MeseroPinResource::make($pin), 'PIN actualizado.');
    }

    public function destroy(int $id, GestionarMeseroPinService $service): JsonResponse
    {
        $usuario = Usuario::findOrFail($id);
        $this->authorize('update', $usuario);

        $service->quitar($usuario);

        return ApiResponse::exito(MeseroPinResource::sinConfigurar(), 'PIN retirado.');
    }

    /**
     * Resuelve quién está tecleando en la terminal compartida.
     *
     * Se exige el modo activo: en un establecimiento con dispositivo por mesero este flujo no
     * debe existir, porque la cuenta que abre la orden YA es la persona y estampar `id_mesero`
     * solo introduciría ruido en los reportes.
     */
    public function identificar(
        IdentificarMeseroRequest $request,
        ResolverMeseroPorPinService $service,
        SesionMeseroTerminal $sesion,
        ModoTerminalCompartida $modo,
    ): JsonResponse {
        $modo->exigirActivo();

        $mesero = $service->resolver((string) $request->validated('pin'), $request->ip());
        $segundos = $modo->segundosBloqueo();

        return ApiResponse::exito([
            'id' => $mesero->id,
            'nombre' => $mesero->nombre,
            // El POS adjunta este token al crear la orden y al cobrar. Es lo que impide que la
            // atribución se pueda falsificar mandando un id a mano (ver SesionMeseroTerminal).
            'token' => $sesion->emitir($mesero, $segundos),
            // Para que la tablet sepa cuándo bloquearse sin consultar la configuración aparte.
            'bloqueo_segundos' => $segundos,
        ]);
    }

    /** Estado del modo, para que el POS sepa si debe pedir PIN. */
    public function modo(Request $request, ModoTerminalCompartida $modo): JsonResponse
    {
        return ApiResponse::exito([
            'terminal_compartida' => $modo->activo(),
            'bloqueo_segundos' => $modo->segundosBloqueo(),
        ]);
    }
}
