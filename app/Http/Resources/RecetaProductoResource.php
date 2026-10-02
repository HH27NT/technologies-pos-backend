<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecetaProductoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_producto' => $this->id_producto,
            'id_insumo' => $this->id_insumo,
            'cantidad' => $this->cantidad,
            'producto' => ProductoResource::make($this->whenLoaded('producto')),
            'insumo' => InsumoResource::make($this->whenLoaded('insumo')),
        ];
    }
}
