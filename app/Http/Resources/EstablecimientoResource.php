<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstablecimientoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'razon_social' => $this->razon_social,
            'rfc' => $this->rfc,
            'direccion' => $this->direccion,
            'telefono' => $this->telefono,
            'email' => $this->email,
            'logo_url' => $this->logo_url,
            'zona_horaria' => $this->zona_horaria,
            'moneda' => $this->moneda,
            'activo' => $this->activo,
            'configuracion' => ConfiguracionEstablecimientoResource::make($this->whenLoaded('configuracion')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
