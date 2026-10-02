<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\Usuario;

/**
 * Autorización de impresión (M13 · matriz Fase 7). Imprimir y reimprimir están
 * permitidos a ADMIN y OPERADOR sin autorización (P14); la reimpresión se audita. El
 * aislamiento por tenant lo garantiza el TenantScope (ticket de otro tenant → 404).
 */
class TicketPolicy
{
    /** Generar/vista previa de un ticket. */
    public function imprimir(Usuario $usuario): bool
    {
        return $usuario->can('tickets.imprimir');
    }

    public function view(Usuario $usuario, Ticket $ticket): bool
    {
        return $usuario->can('tickets.imprimir');
    }

    public function reimprimir(Usuario $usuario, Ticket $ticket): bool
    {
        return $usuario->can('tickets.reimprimir');
    }
}
