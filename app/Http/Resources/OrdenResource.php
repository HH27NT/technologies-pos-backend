<?php

namespace App\Http\Resources;

use App\Domain\Ordenes\MeseroEfectivo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * M11 · Orden. Expone el folio, el estado, los importes congelados (subtotal/descuento/
 * impuesto/total) y los renglones cuando se cargan. Sin fugar id_establecimiento.
 *
 * Atribución (mesero fase 1): `id_usuario` es quien abrió/atiende la orden. Cuando la
 * relación viene cargada se expone `usuario` en forma mínima {id, nombre} — nunca el
 * UsuarioResource completo, para no fugar email/username/roles del mesero por renglón.
 */
class OrdenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'estado' => $this->estado,
            'id_mesa' => $this->id_mesa,
            'id_tipo_orden' => $this->id_tipo_orden,
            'id_sesion_caja' => $this->id_sesion_caja,
            'id_usuario' => $this->id_usuario,
            // Terminal compartida: quién firmó con su PIN. Nulo en el modo normal.
            'id_mesero' => $this->id_mesero,
            // Quién atendió, resuelto para que el frontend no repita la regla COALESCE.
            'id_mesero_efectivo' => MeseroEfectivo::de($this->resource),
            'descuento' => $this->descuento,
            'subtotal' => $this->subtotal,
            'impuesto' => $this->impuesto,
            'total' => $this->total,
            'notas' => $this->notas,
            'abierta_at' => $this->abierta_at,
            'cerrada_at' => $this->cerrada_at,
            'mesa' => MesaResource::make($this->whenLoaded('mesa')),
            'usuario' => $this->whenLoaded('usuario', fn () => [
                'id' => $this->usuario->id,
                'nombre' => $this->usuario->nombre,
            ]),
            // Forma mínima, igual que `usuario`: el POS necesita el nombre para "Atendió: X",
            // no el perfil completo del mesero en cada renglón.
            'mesero' => $this->whenLoaded('mesero', fn () => $this->mesero ? [
                'id' => $this->mesero->id,
                'nombre' => $this->mesero->nombre,
            ] : null),
            'detalles' => DetalleOrdenResource::collection($this->whenLoaded('detalles')),
        ];
    }
}
