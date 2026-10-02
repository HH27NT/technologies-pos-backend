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
 * M11 · Cancelación de un renglón (ADMIN directo en S7). Atómico y auditado (§15).
 *
 * Marca estado_item=cancelado + cancelado_at y recalcula totales (los renglones
 * cancelados no cuentan). La reversa de inventario NO aplica en S7 (nada se descontó
 * aún, P1). El motivo se audita (la tabla no tiene columna motivo). El flujo del
 * operador (🔐) y el enlace id_autorizacion llegan en S9.
 */
class CancelarItemService
{
    use RecalculaTotales;

    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function cancelar(Orden $orden, DetalleOrden $item, array $datos = []): DetalleOrden
    {
        return DB::transaction(function () use ($orden, $item, $datos) {
            $orden = Orden::whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if (! $orden->estadoOrden()->esModificable()) {
                throw new OrdenNoModificableException;
            }

            $item = $orden->detalles()->whereKey($item->id)->firstOrFail();

            if ($item->estado_item === EstadoItem::Cancelado->value) {
                throw new OrdenNoModificableException('El renglón ya está cancelado.');
            }

            $item->estado_item = EstadoItem::Cancelado->value;
            $item->cancelado_at = now();
            // Enlace al flujo de dos niveles (S9): si la cancelación nace de una
            // autorización aprobada, queda trazada por id_autorizacion. ADMIN directo: null.
            if (isset($datos['id_autorizacion'])) {
                $item->id_autorizacion = $datos['id_autorizacion'];
            }
            $item->save();

            $this->recalcularTotales($orden);

            $this->auditoria->registrar(
                accion: 'orden.item_cancelado',
                entidad: 'detalle_orden',
                entidadId: $item->id,
                datosDespues: [
                    'id_orden' => $orden->id,
                    'motivo' => $datos['motivo'] ?? null,
                ],
            );

            return $item;
        });
    }
}
