<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * M15/M16 · Fila de la bitácora de auditoría. Expone la acción, la entidad afectada, el
 * antes/después y el momento, sin fugar más de lo necesario. El nombre del usuario se
 * incluye cuando la relación viene cargada.
 */
class AuditoriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'accion' => $this->accion,
            'entidad' => $this->entidad,
            'entidad_id' => $this->entidad_id,
            'id_usuario' => $this->id_usuario,
            'id_establecimiento' => $this->id_establecimiento,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario?->nombre),
            'datos_antes' => $this->datos_antes,
            'datos_despues' => $this->datos_despues,
            'ip' => $this->ip,
            'created_at' => $this->created_at,
        ];
    }
}
