<?php

namespace App\Domain\Inventario\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Inventario\Events\MovimientoRegistrado;
use App\Domain\Inventario\TipoMovimiento;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Orden;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * M08 · Salvaguarda de reversa de inventario (P3). Revierte ÚNICAMENTE los movimientos
 * de origen `venta` de una orden, asentando un movimiento compensatorio `entrada` por
 * la misma cantidad (el ledger es append-only: no se edita ni borra).
 *
 * NO está cableada a ningún flujo de usuario en S8: cancelar ítem / anular orden
 * ocurren con la orden ABIERTA (antes del cobro), cuando no se ha descontado stock, así
 * que en el flujo normal no hay nada que revertir. Queda como salvaguarda para una
 * eventual anulación post-cobro. Nunca toca entrada/ajuste/merma (P3).
 */
class RevertirMovimientoService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    /** @return int número de movimientos de venta revertidos */
    public function revertirVentasDe(Orden $orden): int
    {
        return DB::transaction(function () use ($orden) {
            $ventas = MovimientoInventario::query()
                ->where('id_orden', $orden->id)
                ->where('tipo', TipoMovimiento::Venta->value)
                ->get();

            foreach ($ventas as $venta) {
                $insumo = Insumo::whereKey($venta->id_insumo)->lockForUpdate()->firstOrFail();
                $stockResultante = (float) $insumo->stock_actual + (float) $venta->cantidad;

                $reversa = MovimientoInventario::create([
                    'id_insumo' => $insumo->id,
                    'id_usuario' => Auth::id(),
                    'id_orden' => $orden->id,
                    'tipo' => TipoMovimiento::Entrada->value,
                    'cantidad' => $venta->cantidad,
                    'stock_resultante' => $stockResultante,
                    'motivo' => 'reversa de venta (orden '.$orden->folio.')',
                ]);

                $insumo->stock_actual = $stockResultante;
                $insumo->save();

                $this->auditoria->registrar(
                    accion: 'inventario.reversa_venta',
                    entidad: 'movimientos_inventario',
                    entidadId: $reversa->id,
                    datosDespues: [
                        'id_insumo' => $insumo->id,
                        'id_orden' => $orden->id,
                        'cantidad' => (float) $venta->cantidad,
                        'stock_resultante' => $stockResultante,
                    ],
                );

                event(new MovimientoRegistrado($reversa));
            }

            return $ventas->count();
        });
    }
}
