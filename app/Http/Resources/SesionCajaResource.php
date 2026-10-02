<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * M10 · Sesión de caja. Expone el ciclo (apertura/cierre) y el arqueo; sin fugar
 * id_establecimiento. Los montos del cierre van nulos mientras la caja está abierta.
 */
class SesionCajaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'estado' => $this->estado,
            'monto_inicial' => $this->monto_inicial,
            'monto_sistema' => $this->monto_sistema,
            'monto_contado' => $this->monto_contado,
            'diferencia' => $this->diferencia,
            'motivo' => $this->motivo,
            'id_usuario_apertura' => $this->id_usuario_apertura,
            'id_usuario_cierre' => $this->id_usuario_cierre,
            'abierta_at' => $this->abierta_at,
            'cerrada_at' => $this->cerrada_at,
            'usuario_apertura' => UsuarioResource::make($this->whenLoaded('usuarioApertura')),
            'usuario_cierre' => UsuarioResource::make($this->whenLoaded('usuarioCierre')),
        ];
    }
}
