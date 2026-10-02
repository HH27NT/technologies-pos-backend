<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MesaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'nombre' => $this->nombre,
            'zona' => $this->zona,
            'capacidad' => $this->capacidad,
            'activa' => $this->activa,
            // Estado derivado de la orden abierta (fuente de verdad: M11). Hasta que
            // Órdenes exista, sin orden abierta ⇒ 'libre'.
            'estado' => $this->relationLoaded('ordenAbierta') && $this->ordenAbierta ? 'ocupada' : 'libre',
        ];
    }
}
