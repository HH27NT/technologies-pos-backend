<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Estado del PIN de un mesero. Solo dice SI está configurado y desde cuándo: el PIN (ni su
 * hash, ni su lookup) nunca sale del backend, ni siquiera para quien lo fijó.
 */
class MeseroPinResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'configurado' => true,
            'actualizado_at' => $this->actualizado_at,
        ];
    }

    /** Payload equivalente cuando el mesero todavía no tiene PIN (o acaba de retirarse). */
    public static function sinConfigurar(): array
    {
        return [
            'configurado' => false,
            'actualizado_at' => null,
        ];
    }
}
