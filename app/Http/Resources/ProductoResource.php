<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_categoria' => $this->id_categoria,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'precio_venta' => $this->precio_venta,
            'costo_referencia' => $this->costo_referencia,
            'controla_inventario' => $this->controla_inventario,
            'disponible' => $this->disponible,
            'sku' => $this->sku,
            'categoria' => CategoriaProductoResource::make($this->whenLoaded('categoria')),
            // Solo cuando el índice la pide con `?con_recetas=1` (pantalla de Recetas).
            // Los renglones vienen sin su `producto`: ya se sabe cuál es.
            'recetas' => RecetaProductoResource::collection($this->whenLoaded('recetas')),
        ];
    }
}
