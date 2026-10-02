<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * M06+M08 · Reglas del atajo "esto sale del almacén": al crear el producto se crea
 * también su insumo y una receta 1:1, para no obligar a pasar por tres pantallas
 * (producto, insumo, receta) por cada botella o lata que se vende tal cual.
 *
 * La presencia del objeto `insumo` es la señal; su ausencia deja el alta como estaba.
 * La unidad la elige el cliente (la global "Pieza" es la habitual) y aqui solo se
 * valida que exista y sea propia del tenant o global (P4, igual que en insumos).
 */
trait ValidaInsumoDelProducto
{
    /** @return array<string, mixed> Reglas con el prefijo dado ("insumo" o "productos.*.insumo"). */
    protected function reglasDeInsumo(string $prefijo, int $tenant): array
    {
        return [
            $prefijo => ['sometimes', 'array'],
            $prefijo.'.id_unidad_medida' => [
                'required_with:'.$prefijo, 'integer',
                Rule::exists('unidades_medida', 'id')->where(
                    fn ($q) => $q->where('id_establecimiento', $tenant)->orWhereNull('id_establecimiento')
                ),
            ],
            $prefijo.'.costo_unitario' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    protected function mensajesDeInsumo(string $prefijo): array
    {
        return [
            $prefijo.'.id_unidad_medida.required_with' => 'Falta la unidad del insumo.',
            $prefijo.'.id_unidad_medida.exists' => 'La unidad de medida no existe o no pertenece a este establecimiento.',
            $prefijo.'.costo_unitario.min' => 'El costo del insumo no puede ser negativo.',
        ];
    }
}
