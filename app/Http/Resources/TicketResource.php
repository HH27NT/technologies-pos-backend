<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * M13 · Ticket (comanda o cobro). Expone el documento y su `contenido_json` (vista
 * previa / payload para PDF). `es_pdf` indica el fallback sin impresora (P15).
 */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_orden' => $this->id_orden,
            'tipo' => $this->tipo,
            'folio_ticket' => $this->folio_ticket,
            'id_impresora' => $this->id_impresora,
            'es_pdf' => $this->id_impresora === null,
            'impreso_at' => $this->impreso_at,
            'contenido_json' => $this->contenido_json,
        ];
    }
}
