<?php

namespace App\Domain\Ordenes\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Ordenes\Concerns\RecalculaTotales;
use App\Domain\Ordenes\EstadoItem;
use App\Models\DetalleOrden;
use App\Models\Orden;
use App\Models\Producto;
use App\Support\Exceptions\OrdenNoModificableException;
use App\Support\Exceptions\ProductoNoDisponibleException;
use Illuminate\Support\Facades\DB;

/**
 * M11 · Alta de un renglón en una orden abierta + recálculo de totales. Atómico y
 * auditado (§15). NO toca inventario (P1: el descuento de stock se dispara al cobrar).
 *
 * Congela el precio (precio_unitario = producto.precio_venta) en el momento de la
 * venta; descuento_item queda en 0 en S7 (el descuento es a nivel orden, P10).
 */
class AgregarItemService
{
    use RecalculaTotales;

    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function agregar(Orden $orden, array $datos): DetalleOrden
    {
        return DB::transaction(function () use ($orden, $datos) {
            $orden = Orden::whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if (! $orden->estadoOrden()->esModificable()) {
                throw new OrdenNoModificableException;
            }

            $producto = Producto::whereKey($datos['id_producto'])->firstOrFail();
            if (! $producto->disponible) {
                throw new ProductoNoDisponibleException;
            }

            $cantidad = (float) $datos['cantidad'];
            $precioUnitario = (float) $producto->precio_venta;
            $subtotal = round($cantidad * $precioUnitario, 2);

            $detalle = DetalleOrden::create([
                'id_orden' => $orden->id,
                'id_producto' => $producto->id,
                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario,
                'descuento_item' => 0,
                'subtotal' => $subtotal,
                'enviado' => false,
                'estado_item' => EstadoItem::Activo->value,
                'notas' => $datos['notas'] ?? null,
            ]);

            $this->recalcularTotales($orden);

            $this->auditoria->registrar(
                accion: 'orden.item_agregado',
                entidad: 'detalle_orden',
                entidadId: $detalle->id,
                datosDespues: [
                    'id_orden' => $orden->id,
                    'id_producto' => $producto->id,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precioUnitario,
                    'subtotal' => $subtotal,
                ],
            );

            return $detalle;
        });
    }
}
