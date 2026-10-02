<?php

namespace App\Jobs;

use App\Models\Ticket;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * M13 · Envío del ticket de cobro a la impresora de tickets (cola `impresion`, con
 * reintentos). NUNCA bloquea el cobro: se encola tras saldar la orden (o on-demand). Si
 * el ticket no tiene impresora (id_impresora null) el documento es PDF (P15). El driver
 * físico real es el punto de extensión; aquí se marca el envío (`impreso_at`). La
 * reimpresión re-encola este job (actualiza el momento del último despacho).
 */
class ImprimirTicketJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(public readonly int $idTicket)
    {
        $this->onQueue('impresion');
    }

    public function handle(): void
    {
        $ticket = Ticket::withoutGlobalScopes()->find($this->idTicket);

        if ($ticket === null) {
            return;
        }

        $ticket->impreso_at = now();
        $ticket->save();
    }
}
