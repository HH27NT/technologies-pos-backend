<?php

namespace App\Http\Resources;

use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rol
 */
class RolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Nombre legible del rol a medida; nulo en los presets, que el frontend
            // etiqueta desde su propio mapa (features/usuarios/roles.ts).
            'etiqueta' => $this->etiqueta,
            'descripcion' => $this->descripcion,
            'id_establecimiento' => $this->id_establecimiento,
            // Distingue preset de rol propio: gobierna qué acciones ofrece la UI
            // (los del sistema solo se clonan, no se editan ni se borran).
            'es_sistema' => $this->resource instanceof Rol ? $this->esDelSistema() : true,
            'permisos' => $this->permissions->pluck('name'),
            // Presente solo cuando se pidió con withCount: evita una consulta por fila
            // en los listados que no lo necesitan.
            'usuarios_count' => $this->whenCounted('usuarios'),
        ];
    }
}
