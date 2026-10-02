<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Autorizaciones\Services\OverrideAutorizacionService;
use App\Domain\Autorizaciones\TipoAutorizacion;
use App\Domain\Ordenes\Services\AgregarItemService;
use App\Domain\Ordenes\Services\AnularOrdenService;
use App\Domain\Ordenes\Services\AplicarDescuentoService;
use App\Domain\Ordenes\Services\CancelarItemService;
use App\Domain\Ordenes\Services\ConfirmarComandaService;
use App\Domain\Ordenes\Services\CrearOrdenService;
use App\Domain\Ordenes\Services\ModificarItemService;
use App\Domain\Ordenes\Services\ReasignarOrdenService;
use App\Domain\Ordenes\Services\SesionMeseroTerminal;
use App\Http\Requests\AnularOrdenRequest;
use App\Http\Requests\AplicarDescuentoRequest;
use App\Http\Requests\CancelarItemRequest;
use App\Http\Requests\CrearOrdenRequest;
use App\Http\Requests\GuardarItemRequest;
use App\Http\Requests\ModificarItemRequest;
use App\Http\Requests\ReasignarOrdenRequest;
use App\Http\Resources\DetalleOrdenResource;
use App\Http\Resources\OrdenResource;
use App\Models\Autorizacion;
use App\Models\Orden;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M11 · Órdenes (OrdenPolicy). ADMIN y OPERADOR crean/operan; cancelar ítem y anular
 * son ADMIN directo (S7). Las rutas de escritura del ciclo abierto exigen caja abierta
 * (middleware caja.abierta). El aislamiento por tenant lo garantiza el TenantScope:
 * id de otro tenant → 404.
 */
class OrdenController extends ApiController
{
    /**
     * Relaciones que acompañan SIEMPRE a una orden devuelta por este controlador.
     *
     * Una sola lista a propósito: cada endpoint cargaba las suyas, y el POS pinta
     * "Atiende: X" con `mesero`/`usuario`. Cuando una respuesta omitía `mesero`, el
     * front no distinguía "no hay mesero firmante" de "no vino cargado" y mostraba la
     * cuenta de la tablet — es decir, atribuía la venta a quien no fue.
     */
    private const RELACIONES_ORDEN = ['mesa', 'usuario', 'mesero', 'detalles.producto'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Orden::class);

        $paginador = Orden::query()
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->when($request->filled('id_mesa'), fn ($q) => $q->where('id_mesa', $request->integer('id_mesa')))
            ->with(['mesa', 'usuario', 'mesero'])
            ->orderByDesc('abierta_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::coleccion($paginador, OrdenResource::class);
    }

    public function show(int $id): JsonResponse
    {
        $orden = Orden::with(self::RELACIONES_ORDEN)->findOrFail($id);
        $this->authorize('view', $orden);

        return ApiResponse::exito(OrdenResource::make($orden));
    }

    public function store(
        CrearOrdenRequest $request,
        CrearOrdenService $service,
        SesionMeseroTerminal $sesion,
    ): JsonResponse {
        $this->authorize('crear', Orden::class);

        $datos = $request->validated();
        // Terminal compartida: la firma llega como token opaco, nunca como id (falsificable).
        // Fuera de ese modo el token no existe y `id_mesero` queda nulo, que es lo correcto.
        $datos['id_mesero'] = $sesion->resolver($request->input('mesero_token'));

        $orden = $service->crear($datos);

        return ApiResponse::creado(
            OrdenResource::make($orden->load(self::RELACIONES_ORDEN)),
            'Orden creada correctamente.'
        );
    }

    public function agregarItem(GuardarItemRequest $request, int $id, AgregarItemService $service): JsonResponse
    {
        $orden = Orden::findOrFail($id);
        $this->authorize('agregarItem', $orden);

        $service->agregar($orden, $request->validated());

        return ApiResponse::creado(
            OrdenResource::make($orden->fresh()->load(self::RELACIONES_ORDEN)),
            'Ítem agregado a la orden.'
        );
    }

    public function modificarItem(ModificarItemRequest $request, int $id, int $itemId, ModificarItemService $service): JsonResponse
    {
        $orden = Orden::findOrFail($id);
        $this->authorize('agregarItem', $orden);

        $item = $orden->detalles()->findOrFail($itemId);
        $service->modificar($orden, $item, $request->validated());

        return ApiResponse::exito(
            OrdenResource::make($orden->fresh()->load(self::RELACIONES_ORDEN)),
            'Ítem actualizado.'
        );
    }

    public function comanda(int $id, ConfirmarComandaService $service): JsonResponse
    {
        $orden = Orden::findOrFail($id);
        $this->authorize('agregarItem', $orden);

        $service->confirmar($orden);

        return ApiResponse::exito(
            OrdenResource::make($orden->fresh()->load(self::RELACIONES_ORDEN)),
            'Comanda confirmada.'
        );
    }

    /**
     * Reasignar el mesero de una orden abierta (traspaso). ADMIN/GERENTE (`ordenes.reasignar`).
     * No pasa por la compuerta de caja (acción de gestión, como cancelar/anular).
     */
    public function reasignar(ReasignarOrdenRequest $request, int $id, ReasignarOrdenService $service): JsonResponse
    {
        $orden = Orden::findOrFail($id);
        $this->authorize('reasignar', $orden);

        $service->reasignar($orden, (int) $request->validated()['id_usuario']);

        return ApiResponse::exito(
            OrdenResource::make($orden->fresh()->load(self::RELACIONES_ORDEN)),
            'Orden reasignada.'
        );
    }

    public function descuento(AplicarDescuentoRequest $request, int $id, AplicarDescuentoService $service): JsonResponse
    {
        $orden = Orden::findOrFail($id);
        $this->authorize('aplicarDescuento', $orden);

        $service->aplicar($orden, $request->validated());

        return ApiResponse::exito(
            OrdenResource::make($orden->fresh()->load(self::RELACIONES_ORDEN)),
            'Descuento aplicado.'
        );
    }

    /**
     * Cancelar renglón. ADMIN con `ordenes.cancelar_item` → directo (como hoy). OPERADOR
     * sin ese permiso → override con PIN de autorización (M14.1). Sin ninguno → 403.
     */
    public function cancelarItem(CancelarItemRequest $request, int $id, int $itemId, CancelarItemService $service, OverrideAutorizacionService $override): JsonResponse
    {
        $orden = Orden::findOrFail($id);
        $item = $orden->detalles()->findOrFail($itemId);
        $datos = $request->validated();
        $usuario = $request->user();

        if ($usuario->can('ordenes.cancelar_item')) {
            $service->cancelar($orden, $item, $datos);
        } elseif ($usuario->can('autorizaciones.solicitar')) {
            $override->ejecutar(
                tipo: TipoAutorizacion::CancelarItem,
                payload: $datos,
                entidadId: $item->id,
                datos: ['id_orden' => $orden->id, 'id_item' => $item->id],
                ejecutar: fn (Autorizacion $a) => $service->cancelar($orden, $item, [
                    'motivo' => $datos['motivo'] ?? null,
                    'id_autorizacion' => $a->id,
                ]),
                ip: $request->ip(),
            );
        } else {
            $this->authorize('cancelarItem', $orden);
        }

        return ApiResponse::exito(
            DetalleOrdenResource::make($item->fresh()),
            'Ítem cancelado.'
        );
    }

    /**
     * Anular orden. ADMIN con `ordenes.anular` → directo (como hoy). OPERADOR sin ese
     * permiso → override con PIN de autorización (M14.1). Sin ninguno → 403.
     */
    public function anular(AnularOrdenRequest $request, int $id, AnularOrdenService $service, OverrideAutorizacionService $override): JsonResponse
    {
        $orden = Orden::findOrFail($id);
        $datos = $request->validated();
        $usuario = $request->user();

        if ($usuario->can('ordenes.anular')) {
            $service->anular($orden, $datos);
        } elseif ($usuario->can('autorizaciones.solicitar')) {
            $override->ejecutar(
                tipo: TipoAutorizacion::AnularOrden,
                payload: $datos,
                entidadId: $orden->id,
                datos: ['id_orden' => $orden->id],
                ejecutar: fn (Autorizacion $a) => $service->anular($orden, ['motivo' => $datos['motivo'] ?? null]),
                ip: $request->ip(),
            );
        } else {
            $this->authorize('anular', $orden);
        }

        return ApiResponse::exito(
            OrdenResource::make($orden->fresh()),
            'Orden anulada.'
        );
    }
}
