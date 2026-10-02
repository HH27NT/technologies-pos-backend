<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsumoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'tipo' => $this->tipo,
            'id_unidad_medida' => $this->id_unidad_medida,
            'id_proveedor' => $this->id_proveedor,
            'stock_actual' => $this->stock_actual,
            'stock_minimo' => $this->stock_minimo,
            'costo_unitario' => $this->costo_unitario,
            'activo' => $this->activo,
            // Bandera derivada para alertas/listado (no es columna).
            'stock_bajo' => $this->stock_minimo !== null
                && (float) $this->stock_actual <= (float) $this->stock_minimo,
            'unidad_medida' => UnidadMedidaResource::make($this->whenLoaded('unidadMedida')),
            'proveedor' => ProveedorResource::make($this->whenLoaded('proveedor')),
        ];
    }
}
