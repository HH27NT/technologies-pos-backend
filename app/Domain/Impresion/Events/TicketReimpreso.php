<?php

namespace App\Domain\Impresion\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * M13 · Un ticket fue reimpreso (P14: permitido a ambos roles sin autorización, pero
 * auditado). Se emite dentro de la transacción de la reimpresión (§15).
 */
class TicketReimpreso
{
    use Dispatchable;

    public function __construct(public readonly Ticket $ticket) {}
}
