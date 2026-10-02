<?php

namespace App\Domain\Impresion\Services;

use App\Domain\Impresion\ContenidoTicket;
use App\Domain\Impresion\SelectorImpresora;
use App\Domain\Impresion\TipoTicket;
use App\Domain\Ordenes\EstadoOrden;
use App\Jobs\ImprimirTicketJob;
use App\Models\ConfiguracionEstablecimiento;
use App\Models\Orden;
use App\Models\Ticket;
use App\Support\Exceptions\TicketNoGenerableException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * M13 · Genera el ticket de cobro (comprobante de venta) desde la configuración, la orden
 * y sus pagos. Exige orden `pagada` (D5). Asigna folio_ticket continuo por tenant,
 * persiste el registro (síncrono) y ENCOLA la impresión (nunca bloquea el cobro). Sin
 * impresora del tipo → PDF (P15, id_impresora null).
 */
class GenerarTicketService
{
    public function generar(Orden $orden): Ticket
    {
        return DB::transaction(function () use ($orden) {
            // `mesero`/`usuario`: el ticket imprime quién atendió, y el contenido se congela
            // aquí — si la relación no viene, el comprobante sale sin nombre para siempre.
            $orden->loadMissing(['detalles.producto', 'tipoOrden', 'mesa', 'pagos.tipoPago', 'mesero', 'usuario']);

            if ($orden->estadoOrden() !== EstadoOrden::Pagada) {
                throw new TicketNoGenerableException('Solo se emite ticket de cobro de una orden pagada.');
            }

            $config = ConfiguracionEstablecimiento::where('id_establecimiento', $orden->id_establecimiento)->first();
            $folio = 'T'.str_pad((string) (new Ticket)->proximoCorrelativo('folio_ticket', $orden->id_establecimiento), 6, '0', STR_PAD_LEFT);
            $impresora = SelectorImpresora::para(TipoTicket::Cobro);

            $ticket = Ticket::create([
                'id_orden' => $orden->id,
                'id_usuario' => Auth::id(),
                'id_impresora' => $impresora?->id,
                'folio_ticket' => $folio,
                'contenido_json' => ContenidoTicket::cobro($orden, $config, $folio),
                'tipo' => TipoTicket::Cobro->value,
            ]);

            // Driver `database`: el job se inserta en la misma transacción y solo es
            // visible al worker tras el commit (no bloquea el cobro ni se pierde).
            ImprimirTicketJob::dispatch($ticket->id);

            return $ticket;
        });
    }
}
