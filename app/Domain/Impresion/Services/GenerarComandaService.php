<?php

namespace App\Domain\Impresion\Services;

use App\Domain\Impresion\ContenidoTicket;
use App\Domain\Impresion\SelectorImpresora;
use App\Domain\Impresion\TipoTicket;
use App\Domain\Ordenes\EstadoItem;
use App\Jobs\EnviarComandaJob;
use App\Models\ConfiguracionEstablecimiento;
use App\Models\Orden;
use App\Models\Ticket;
use App\Support\Exceptions\TicketNoGenerableException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * M13 · Genera la comanda (lo que va a cocina/barra) desde la configuración y la orden.
 * Persiste el registro en `tickets` (síncrono) y ENCOLA el envío físico (nunca bloquea
 * la operación). Sin impresora del tipo → PDF (P15, id_impresora null).
 */
class GenerarComandaService
{
    public function generar(Orden $orden): Ticket
    {
        return DB::transaction(function () use ($orden) {
            $orden->loadMissing(['detalles.producto', 'tipoOrden', 'mesa']);

            $hayEnviados = $orden->detalles
                ->where('estado_item', EstadoItem::Activo->value)
                ->where('enviado', true)
                ->isNotEmpty();

            if (! $hayEnviados) {
                throw new TicketNoGenerableException('No hay renglones enviados para la comanda.');
            }

            $config = ConfiguracionEstablecimiento::where('id_establecimiento', $orden->id_establecimiento)->first();
            $impresora = SelectorImpresora::para(TipoTicket::Comanda);

            $ticket = Ticket::create([
                'id_orden' => $orden->id,
                'id_usuario' => Auth::id(),
                'id_impresora' => $impresora?->id,
                'folio_ticket' => null,
                'contenido_json' => ContenidoTicket::comanda($orden, $config),
                'tipo' => TipoTicket::Comanda->value,
            ]);

            // Driver `database`: el job se inserta en la misma transacción y solo es
            // visible al worker tras el commit (no bloquea ni se pierde).
            EnviarComandaJob::dispatch($ticket->id);

            return $ticket;
        });
    }
}
