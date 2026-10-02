<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * No expone columnas internas (password_hash, remember_token). Los roles se leen del
 * team activo de Spatie; es_super_admin distingue al actor global.
 */
class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_establecimiento' => $this->id_establecimiento,
            'nombre' => $this->nombre,
            'email' => $this->email,
            'username' => $this->username,
            'activo' => $this->activo,
            'id_rol' => $this->id_rol,
            'es_super_admin' => $this->esSuperAdmin(),
            'roles' => $this->getRoleNames(),
        ];
    }
}
