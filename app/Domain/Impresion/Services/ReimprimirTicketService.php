<?php

namespace App\Domain\Impresion\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Impresion\Events\TicketReimpreso;
use App\Domain\Impresion\TipoTicket;
use App\Jobs\EnviarComandaJob;
use App\Jobs\ImprimirTicketJob;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * M13 · Reimpresión de un ticket existente (P14: ambos roles, sin autorización, pero
 * AUDITADA). Re-encola el job del mismo documento (mismo contenido_json) y audita
 * `ticket.reimpreso` dentro de la transacción (§15).
 */
class ReimprimirTicketService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function reimprimir(Ticket $ticket): Ticket
    {
        return DB::transaction(function () use ($ticket) {
            // Driver `database`: el job se encola en la misma transacción que la
            // auditoría de reimpresión; visible al worker tras el commit.
            $ticket->tipo === TipoTicket::Comanda->value
                ? EnviarComandaJob::dispatch($ticket->id)
                : ImprimirTicketJob::dispatch($ticket->id);

            $this->auditoria->registrar(
                accion: 'ticket.reimpreso',
                entidad: 'tickets',
                entidadId: $ticket->id,
                datosDespues: [
                    'id_orden' => $ticket->id_orden,
                    'tipo' => $ticket->tipo,
                    'folio_ticket' => $ticket->folio_ticket,
                ],
            );

            event(new TicketReimpreso($ticket));

            return $ticket;
        });
    }
}
