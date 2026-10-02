<?php

namespace App\Domain\Ordenes\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Ordenes\EstadoItem;
use App\Domain\Ordenes\Events\ItemConfirmado;
use App\Models\Orden;
use App\Support\Exceptions\OrdenNoModificableException;
use Illuminate\Support\Facades\DB;

/**
 * M11 · Confirmación de comanda. Atómica y auditada (§15).
 *
 * Marca los renglones `activo` con enviado=false → enviado=true. NO toca inventario
 * (P1). Comanda incremental: los ítems agregados después se envían en una comanda
 * posterior. La impresión física es S10 (consumidor de ItemConfirmado); aquí solo se
 * marca el estado y se emite el evento.
 */
class ConfirmarComandaService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function confirmar(Orden $orden): Orden
    {
        return DB::transaction(function () use ($orden) {
            $orden = Orden::whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if (! $orden->estadoOrden()->esModificable()) {
                throw new OrdenNoModificableException;
            }

            $pendientes = $orden->detalles()
                ->where('estado_item', EstadoItem::Activo->value)
                ->where('enviado', false)
                ->update(['enviado' => true]);

            $this->auditoria->registrar(
                accion: 'orden.comanda',
                entidad: 'ordenes',
                entidadId: $orden->id,
                datosDespues: ['items_enviados' => $pendientes],
            );

            event(new ItemConfirmado($orden));

            return $orden;
        });
    }
}
