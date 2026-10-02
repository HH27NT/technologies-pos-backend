<?php

namespace App\Domain\Ordenes\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Ordenes\Concerns\RecalculaTotales;
use App\Domain\Ordenes\EstadoItem;
use App\Models\DetalleOrden;
use App\Models\Orden;
use App\Support\Exceptions\OrdenNoModificableException;
use Illuminate\Support\Facades\DB;

/**
 * M11 · Modificación de la cantidad de un renglón + recálculo. Atómico y auditado (§15).
 *
 * Solo se permite si el renglón está `activo` y con enviado=false: un ítem ya enviado
 * a comanda no cambia de cantidad (→ 422). Recongela el subtotal del renglón.
 */
class ModificarItemService
{
    use RecalculaTotales;

    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function modificar(Orden $orden, DetalleOrden $item, array $datos): DetalleOrden
    {
        return DB::transaction(function () use ($orden, $item, $datos) {
            $orden = Orden::whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if (! $orden->estadoOrden()->esModificable()) {
                throw new OrdenNoModificableException;
            }

            $item = $orden->detalles()->whereKey($item->id)->firstOrFail();

            if ($item->enviado || $item->estado_item !== EstadoItem::Activo->value) {
                throw new OrdenNoModificableException('El renglón ya fue enviado a comanda o está cancelado.');
            }

            $cantidad = (float) $datos['cantidad'];
            $antes = (float) $item->cantidad;

            $item->cantidad = $cantidad;
            $item->subtotal = round($cantidad * (float) $item->precio_unitario - (float) $item->descuento_item, 2);
            $item->save();

            $this->recalcularTotales($orden);

            $this->auditoria->registrar(
                accion: 'orden.item_modificado',
                entidad: 'detalle_orden',
                entidadId: $item->id,
                datosAntes: ['cantidad' => $antes],
                datosDespues: ['cantidad' => $cantidad, 'subtotal' => (float) $item->subtotal],
            );

            return $item;
        });
    }
}
