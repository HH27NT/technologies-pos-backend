<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * M14.1 · Estado del PIN de autorización del usuario. Solo dice SI está configurado y desde
 * cuándo: el PIN (ni su hash, ni su lookup) nunca sale del backend.
 */
class AutorizacionPinResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'configurado' => true,
            'actualizado_at' => $this->actualizado_at,
        ];
    }

    /** Payload equivalente cuando el usuario todavía no tiene PIN (o acaba de borrarlo). */
    public static function sinConfigurar(): array
    {
        return [
            'configurado' => false,
            'actualizado_at' => null,
        ];
    }
}
