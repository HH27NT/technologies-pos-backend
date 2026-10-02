<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * M11 · Renglón de orden. Expone los importes congelados y el estado de envío/cancelación
 * sin fugar id_establecimiento.
 */
class DetalleOrdenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_producto' => $this->id_producto,
            'cantidad' => $this->cantidad,
            'precio_unitario' => $this->precio_unitario,
            'descuento_item' => $this->descuento_item,
            'subtotal' => $this->subtotal,
            'enviado' => $this->enviado,
            'estado_item' => $this->estado_item,
            'cancelado_at' => $this->cancelado_at,
            'notas' => $this->notas,
            'producto' => ProductoResource::make($this->whenLoaded('producto')),
        ];
    }
}
