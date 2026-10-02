<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnidadMedidaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'abreviacion' => $this->abreviacion,
            // Global (predefinida, solo lectura) vs. propia del establecimiento (P4).
            'es_global' => $this->esGlobal(),
        ];
    }
}
