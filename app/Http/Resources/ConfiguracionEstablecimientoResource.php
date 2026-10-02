<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfiguracionEstablecimientoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre_comercial' => $this->nombre_comercial,
            'telefono_ticket' => $this->telefono_ticket,
            'direccion_ticket' => $this->direccion_ticket,
            'impresion_automatica' => $this->impresion_automatica,
            // Modo "terminal compartida" (PIN de mesero). Se expone aquí y no solo en
            // GET /terminal/modo porque esa ruta es la lectura del POS: la pantalla de
            // Configuración necesita el valor junto al resto de ajustes que edita.
            'terminal_compartida' => $this->terminal_compartida,
            'bloqueo_terminal_segundos' => $this->bloqueo_terminal_segundos,
            'stock_minimo_global' => $this->stock_minimo_global,
            'aplica_impuesto' => $this->aplica_impuesto,
            'tasa_impuesto' => $this->tasa_impuesto,
        ];
    }
}
