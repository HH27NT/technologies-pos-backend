<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * M14 · Solicitud de autorización. Expone el tipo, el sujeto (entidad/entidad_id), el
 * estado y la trazabilidad de solicitante/autorizador sin fugar id_establecimiento.
 */
class AutorizacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'entidad' => $this->entidad,
            'entidad_id' => $this->entidad_id,
            'estado' => $this->estado,
            'metodo' => $this->metodo,
            'motivo' => $this->motivo,
            'datos' => $this->datos,
            'id_usuario_solicita' => $this->id_usuario_solicita,
            'id_usuario_autoriza' => $this->id_usuario_autoriza,
            'resuelta_at' => $this->resuelta_at,
            'created_at' => $this->created_at,
            'solicitante' => $this->whenLoaded('usuarioSolicita', fn () => $this->usuarioSolicita->nombre),
        ];
    }
}
