<?php

namespace App\Jobs;

use App\Models\Ticket;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * M13 · Envío de la comanda a la impresora de cocina/barra (cola `impresion`, con
 * reintentos). NUNCA bloquea la operación de venta: se encola tras confirmar la comanda.
 * Si el ticket no tiene impresora (id_impresora null) el documento es PDF (P15). El
 * driver físico real es el punto de extensión; aquí se marca el envío (`impreso_at`).
 */
class EnviarComandaJob implements ShouldQueue
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

        // Aquí iría el envío real al driver de impresión (ESC/POS) o la generación del
        // PDF si no hay impresora. En el MVP se registra el momento del despacho.
        $ticket->impreso_at = now();
        $ticket->save();
    }
}
