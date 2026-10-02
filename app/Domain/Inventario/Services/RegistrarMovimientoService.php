<?php

namespace App\Domain\Inventario\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Inventario\Events\MovimientoRegistrado;
use App\Domain\Inventario\Events\StockBajoDetectado;
use App\Domain\Inventario\TipoMovimiento;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Support\Exceptions\StockInsuficienteException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * M08 · Registro de movimientos MANUALES en el ledger (fuente de verdad del stock).
 * Append-only: actualiza insumos.stock_actual (cache) y fija stock_resultante DENTRO
 * de la misma transacción (§15). Bloqueo pesimista sobre el insumo para evitar
 * condiciones de carrera en el stock.
 *
 * Tipos que SUMAN: entrada, ajuste (corrección positiva).
 * Tipos que RESTAN: merma, rotura, consumo_interno.
 * 'venta'/'salida' los origina el cobro (Sprint 8), no este servicio.
 */
class RegistrarMovimientoService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function registrar(array $datos): MovimientoInventario
    {
        return DB::transaction(function () use ($datos) {
            $insumo = Insumo::whereKey($datos['id_insumo'])->lockForUpdate()->firstOrFail();

            $tipo = $datos['tipo'];
            $cantidad = (float) $datos['cantidad'];
            $delta = TipoMovimiento::from($tipo)->signo() * $cantidad;
            $stockResultante = (float) $insumo->stock_actual + $delta;

            // D1: una salida manual no puede dejar el stock en negativo (eso solo lo
            // permite la venta, P2). Se bloquea como error de captura.
            if ($delta < 0 && $stockResultante < 0) {
                throw new StockInsuficienteException;
            }

            $movimiento = MovimientoInventario::create([
                'id_insumo' => $insumo->id,
                'id_usuario' => Auth::id(),
                'tipo' => $tipo,
                'cantidad' => $cantidad,
                'costo_unitario' => $datos['costo_unitario'] ?? null,
                'stock_resultante' => $stockResultante,
                'motivo' => $datos['motivo'] ?? null,
                // Enlace al flujo de dos niveles (S9): entrada/ajuste solicitados por el
                // operador y aprobados quedan trazados por id_autorizacion. Directo: null.
                'id_autorizacion' => $datos['id_autorizacion'] ?? null,
            ]);

            $insumo->stock_actual = $stockResultante;
            $insumo->save();

            $this->auditoria->registrar(
                accion: 'inventario.'.$tipo,
                entidad: 'movimientos_inventario',
                entidadId: $movimiento->id,
                datosDespues: [
                    'id_insumo' => $insumo->id,
                    'tipo' => $tipo,
                    'cantidad' => $cantidad,
                    'stock_resultante' => $stockResultante,
                    'motivo' => $movimiento->motivo,
                ],
            );

            event(new MovimientoRegistrado($movimiento));

            if ($insumo->stock_minimo !== null && $stockResultante <= (float) $insumo->stock_minimo) {
                event(new StockBajoDetectado($insumo));
            }

            return $movimiento;
        });
    }
}
