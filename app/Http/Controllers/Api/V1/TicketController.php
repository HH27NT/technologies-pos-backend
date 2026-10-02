<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Impresion\Services\GenerarTicketService;
use App\Domain\Impresion\Services\ReimprimirTicketService;
use App\Http\Resources\TicketResource;
use App\Models\Orden;
use App\Models\Ticket;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * M13 · Impresión (TicketPolicy; ADMIN y OPERADOR, P14). Generar el ticket de cobro,
 * reimprimir (auditada) y vista previa. El aislamiento por tenant lo garantiza el
 * TenantScope: ticket/orden de otro tenant → 404.
 */
class TicketController extends ApiController
{
    /** Genera (on-demand) el ticket de cobro de una orden pagada. */
    public function generar(int $idOrden, GenerarTicketService $service): JsonResponse
    {
        $orden = Orden::findOrFail($idOrden);
        $this->authorize('imprimir', Ticket::class);

        $ticket = $service->generar($orden);

        return ApiResponse::creado(
            TicketResource::make($ticket),
            'Ticket de cobro generado.'
        );
    }

    /** Reimprime un ticket existente (ambos roles, sin autorización, auditada). */
    public function reimprimir(int $id, ReimprimirTicketService $service): JsonResponse
    {
        $ticket = Ticket::findOrFail($id);
        $this->authorize('reimprimir', $ticket);

        $service->reimprimir($ticket);

        return ApiResponse::exito(
            TicketResource::make($ticket->fresh()),
            'Ticket reenviado a impresión.'
        );
    }

    /** Vista previa / payload del documento (contenido_json), PDF si no hay impresora. */
    public function show(int $id): JsonResponse
    {
        $ticket = Ticket::findOrFail($id);
        $this->authorize('view', $ticket);

        return ApiResponse::exito(TicketResource::make($ticket));
    }
}
