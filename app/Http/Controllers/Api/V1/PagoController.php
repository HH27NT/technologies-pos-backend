<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ordenes\Services\SesionMeseroTerminal;
use App\Domain\Pagos\Services\RegistrarPagoService;
use App\Http\Requests\RegistrarPagoRequest;
use App\Http\Resources\PagoResource;
use App\Models\Orden;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * M12 · Pagos de orden (OrdenPolicy::cobrar; ADMIN y OPERADOR). El cobro exige caja
 * abierta (middleware caja.abierta). El aislamiento por tenant lo garantiza el
 * TenantScope: orden de otro tenant → 404.
 */
class PagoController extends ApiController
{
    public function store(
        RegistrarPagoRequest $request,
        int $id,
        RegistrarPagoService $service,
        SesionMeseroTerminal $sesion,
    ): JsonResponse {
        $orden = Orden::findOrFail($id);
        $this->authorize('cobrar', $orden);

        $datos = $request->validated();
        // El cobro se firma aparte de la apertura: quien abre la mesa no siempre es quien cobra.
        $datos['id_mesero'] = $sesion->resolver($request->input('mesero_token'));

        $resultado = $service->registrar($orden, $datos);
        $orden = $resultado['orden']->fresh();

        return ApiResponse::creado([
            'pago' => PagoResource::make($resultado['pago']->load('tipoPago'))->resolve(),
            'cambio' => $resultado['cambio'],
            'saldo' => $resultado['saldo'],
            'estado_orden' => $orden->estado,
            // Insumos que esta venta dejó en negativo (P2: nunca bloquea, solo avisa).
            'avisos_stock' => $resultado['avisos_stock'] ?? [],
        ], $orden->estado === 'pagada' ? 'Orden pagada correctamente.' : 'Pago registrado.');
    }

    /** Saldo pendiente de la orden (total congelado − Σ pagos). */
    public function saldo(int $id): JsonResponse
    {
        $orden = Orden::findOrFail($id);
        $this->authorize('view', $orden);

        $pagado = round((float) $orden->pagos()->sum('monto'), 2);
        $total = round((float) $orden->total, 2);

        return ApiResponse::exito([
            'total' => $total,
            'pagado' => $pagado,
            'saldo' => round($total - $pagado, 2),
            'pagada' => $orden->estado === 'pagada',
        ]);
    }
}
