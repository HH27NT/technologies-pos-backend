<?php

namespace App\Domain\Ordenes\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Ordenes\EstadoOrden;
use App\Domain\Ordenes\Events\OrdenAnulada;
use App\Models\Orden;
use App\Support\Exceptions\OrdenNoModificableException;
use Illuminate\Support\Facades\DB;

/**
 * M11 · Anulación de una orden abierta (ADMIN directo en S7). Atómico y auditado (§15).
 *
 * estado=anulada + cerrada_at; la mesa se libera por DERIVACIÓN (regla global 11: el
 * estado de mesa se deriva de la orden abierta, no se persiste). La reversa de
 * inventario no aplica pre-cobro (P1). Audita la pérdida (walkout). El flujo del
 * operador llega en S9.
 */
class AnularOrdenService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function anular(Orden $orden, array $datos = []): Orden
    {
        return DB::transaction(function () use ($orden, $datos) {
            $orden = Orden::whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if (! $orden->estadoOrden()->esModificable()) {
                throw new OrdenNoModificableException;
            }

            $orden->estado = EstadoOrden::Anulada->value;
            $orden->cerrada_at = now();
            $orden->save();

            $this->auditoria->registrar(
                accion: 'orden.anulada',
                entidad: 'ordenes',
                entidadId: $orden->id,
                datosDespues: [
                    'folio' => $orden->folio,
                    'motivo' => $datos['motivo'] ?? null,
                ],
            );

            event(new OrdenAnulada($orden));

            return $orden;
        });
    }
}
