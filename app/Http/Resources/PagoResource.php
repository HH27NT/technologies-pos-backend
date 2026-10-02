<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * M12 · Pago de orden. Expone el importe aplicado y la referencia sin fugar
 * id_establecimiento. La propina no se expone (reservada V2, P9).
 */
class PagoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_orden' => $this->id_orden,
            'id_tipo_pago' => $this->id_tipo_pago,
            'id_usuario' => $this->id_usuario,
            'monto' => $this->monto,
            'referencia' => $this->referencia,
            'pagado_at' => $this->pagado_at,
            'tipo_pago' => $this->whenLoaded('tipoPago', fn () => $this->tipoPago->nombre),
        ];
    }
}
