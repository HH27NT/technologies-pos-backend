<?php

namespace App\Domain\Pagos\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Inventario\TipoMovimiento;
use App\Domain\Ordenes\EstadoOrden;
use App\Domain\Pagos\Events\OrdenPagada;
use App\Domain\Pagos\Events\PagoRegistrado;
use App\Models\MovimientoInventario;
use App\Models\Orden;
use App\Models\Pago;
use App\Models\TipoPago;
use App\Support\Exceptions\OrdenNoCobrableException;
use App\Support\Exceptions\SobrepagoNoPermitidoException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * M12 · Registro de un pago de orden (simple o dividido por monto, P8). Atómico y
 * auditado (§15). Sin propina (P9).
 *
 * Idempotencia (D1): si llega `referencia` y ya existe un pago (id_orden, referencia),
 * se devuelve ese pago SIN recrear (un reintento de red no genera doble cobro). El
 * índice único parcial en pgsql es la última salvaguarda.
 *
 * Cierre: cuando el saldo llega a 0 la orden pasa a `pagada`, emite `OrdenPagada`
 * (dispara el descuento de inventario en la misma transacción, P1) y se audita.
 * El resultado trae `avisos_stock`: los insumos que esta venta dejó en negativo
 * (P2 no bloquea la venta, pero exige "mostrar alerta"; antes solo se veía revisando
 * el dashboard después, ahora viaja en la respuesta del propio cobro).
 *
 * Sobrepago: solo el efectivo lo admite (devuelve cambio, NO se almacena); el monto
 * aplicado y guardado es min(monto, saldo). Tarjeta/transferencia: monto ≤ saldo.
 */
class RegistrarPagoService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    /**
     * @return array{pago: Pago, cambio: float, saldo: float, orden: Orden, idempotente: bool, avisos_stock: array}
     */
    public function registrar(Orden $orden, array $datos): array
    {
        return DB::transaction(function () use ($orden, $datos) {
            $orden = Orden::whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if ($orden->estadoOrden() !== EstadoOrden::Abierta) {
                throw new OrdenNoCobrableException;
            }

            $referencia = $datos['referencia'] ?? null;
            if ($referencia !== null) {
                $previo = $orden->pagos()->where('referencia', $referencia)->first();
                if ($previo !== null) {
                    // Reintento idempotente: mismo resultado, sin recobrar ni recerrar.
                    return [
                        'pago' => $previo,
                        'cambio' => 0.0,
                        'saldo' => $this->saldoPendiente($orden),
                        'orden' => $orden,
                        'idempotente' => true,
                        'avisos_stock' => [],
                    ];
                }
            }

            $saldo = $this->saldoPendiente($orden);
            if ($saldo <= 0.0) {
                throw new OrdenNoCobrableException('La orden no tiene saldo pendiente.');
            }

            $monto = round((float) $datos['monto'], 2);
            $esEfectivo = TipoPago::whereKey($datos['id_tipo_pago'])->value('nombre') === 'efectivo';

            // Efectivo admite sobrepago (cambio); el resto no.
            if (! $esEfectivo && $monto > $saldo) {
                throw new SobrepagoNoPermitidoException;
            }

            $aplicado = min($monto, $saldo);
            $cambio = round($monto - $aplicado, 2);

            $pago = Pago::create([
                'id_orden' => $orden->id,
                'id_tipo_pago' => $datos['id_tipo_pago'],
                'id_usuario' => Auth::id(),
                // Firma del cobro en terminal compartida. Se pide PIN otra vez aquí aunque el
                // mesero ya estuviera activo: quien abre la mesa no siempre es quien cobra, y
                // el dinero se firma en el momento.
                'id_mesero' => $datos['id_mesero'] ?? null,
                'monto' => $aplicado,
                'referencia' => $referencia,
                'pagado_at' => now(),
            ]);

            $this->auditoria->registrar(
                accion: 'orden.pago_registrado',
                entidad: 'pagos',
                entidadId: $pago->id,
                datosDespues: [
                    'id_orden' => $orden->id,
                    'monto' => $aplicado,
                    'id_tipo_pago' => $pago->id_tipo_pago,
                ],
            );

            event(new PagoRegistrado($pago));

            $saldoRestante = round($saldo - $aplicado, 2);
            $avisosStock = [];
            if ($saldoRestante <= 0.0) {
                $avisosStock = $this->cerrarOrden($orden);
            }

            return [
                'pago' => $pago,
                'cambio' => $cambio,
                'saldo' => max($saldoRestante, 0.0),
                'orden' => $orden,
                'idempotente' => false,
                'avisos_stock' => $avisosStock,
            ];
        });
    }

    /** saldo = total congelado − Σ pagos asentados. */
    private function saldoPendiente(Orden $orden): float
    {
        $pagado = (float) $orden->pagos()->sum('monto');

        return round((float) $orden->total - $pagado, 2);
    }

    /**
     * Cierra la orden saldada y dispara el descuento de inventario (P1) vía OrdenPagada
     * (listener síncrono, misma transacción).
     *
     * @return array<int, array{id_insumo: int, insumo: ?string, stock_resultante: float}>
     */
    private function cerrarOrden(Orden $orden): array
    {
        $orden->estado = EstadoOrden::Pagada->value;
        $orden->cerrada_at = now();
        $orden->save();

        $this->auditoria->registrar(
            accion: 'orden.pagada',
            entidad: 'ordenes',
            entidadId: $orden->id,
            datosDespues: ['folio' => $orden->folio, 'total' => (float) $orden->total],
        );

        event(new OrdenPagada($orden));

        // P2: la venta NUNCA se bloquea por falta de stock, pero sí "muestra alerta".
        // El listener de arriba ya corrió (síncrono): se lee qué insumos de ESTA venta
        // quedaron en negativo para devolverlo en la respuesta del cobro — así quien
        // cobra lo ve al momento, no hasta que alguien revise el dashboard después.
        return MovimientoInventario::where('id_orden', $orden->id)
            ->where('tipo', TipoMovimiento::Venta->value)
            ->where('stock_resultante', '<', 0)
            ->with('insumo:id,nombre')
            ->get()
            ->map(fn (MovimientoInventario $m) => [
                'id_insumo' => $m->id_insumo,
                'insumo' => $m->insumo?->nombre,
                'stock_resultante' => (float) $m->stock_resultante,
            ])
            ->values()
            ->all();
    }
}
