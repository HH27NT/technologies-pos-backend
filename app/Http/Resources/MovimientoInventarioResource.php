<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoInventarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_insumo' => $this->id_insumo,
            'tipo' => $this->tipo,
            'cantidad' => $this->cantidad,
            'costo_unitario' => $this->costo_unitario,
            'stock_resultante' => $this->stock_resultante,
            'motivo' => $this->motivo,
            'id_usuario' => $this->id_usuario,
            'created_at' => $this->created_at,
        ];
    }
}
