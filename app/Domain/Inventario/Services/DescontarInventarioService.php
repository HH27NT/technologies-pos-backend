<?php

namespace App\Domain\Inventario\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Inventario\Events\MovimientoRegistrado;
use App\Domain\Inventario\Events\StockBajoDetectado;
use App\Domain\Inventario\TipoMovimiento;
use App\Domain\Ordenes\EstadoItem;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Orden;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * M08 · Descuento de inventario AL COBRAR (P1). Lo dispara el evento OrdenPagada
 * (listener síncrono) DENTRO de la transacción del cobro (§15): si el cobro se
 * revierte, el descuento también.
 *
 * Recorre los renglones ACTIVOS de la orden; por cada producto que `controla_inventario`
 * y tiene receta, asienta un movimiento `venta` por insumo con cantidad =
 * receta.cantidad × renglón.cantidad. PERMITE stock negativo (P2): la venta nunca se
 * bloquea por falta de stock (a diferencia de la salida manual del S5). Productos sin
 * control de inventario o sin receta NO generan movimiento.
 */
class DescontarInventarioService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function descontar(Orden $orden): void
    {
        DB::transaction(function () use ($orden) {
            $detalles = $orden->detalles()
                ->where('estado_item', EstadoItem::Activo->value)
                ->with('producto.recetas')
                ->get();

            foreach ($detalles as $detalle) {
                $producto = $detalle->producto;
                if ($producto === null || ! $producto->controla_inventario) {
                    continue;
                }

                foreach ($producto->recetas as $receta) {
                    $this->descontarInsumo(
                        $orden,
                        (int) $receta->id_insumo,
                        (float) $receta->cantidad * (float) $detalle->cantidad,
                    );
                }
            }
        });
    }

    private function descontarInsumo(Orden $orden, int $idInsumo, float $consumo): void
    {
        $insumo = Insumo::whereKey($idInsumo)->lockForUpdate()->firstOrFail();

        // P2: la venta puede dejar el stock en negativo (no se bloquea).
        $stockResultante = (float) $insumo->stock_actual - $consumo;

        $movimiento = MovimientoInventario::create([
            'id_insumo' => $insumo->id,
            'id_usuario' => Auth::id(),
            'id_orden' => $orden->id,
            'tipo' => TipoMovimiento::Venta->value,
            'cantidad' => $consumo,
            'costo_unitario' => $insumo->costo_unitario,
            'stock_resultante' => $stockResultante,
        ]);

        $insumo->stock_actual = $stockResultante;
        $insumo->save();

        $this->auditoria->registrar(
            accion: 'inventario.venta',
            entidad: 'movimientos_inventario',
            entidadId: $movimiento->id,
            datosDespues: [
                'id_insumo' => $insumo->id,
                'id_orden' => $orden->id,
                'cantidad' => $consumo,
                'stock_resultante' => $stockResultante,
            ],
        );

        event(new MovimientoRegistrado($movimiento));

        if ($insumo->stock_minimo !== null && $stockResultante <= (float) $insumo->stock_minimo) {
            event(new StockBajoDetectado($insumo));
        }
    }
}
