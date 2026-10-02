<?php

namespace App\Http\Requests;

use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M07 · Receta COMPLETA de un producto: `PUT /recetas/producto/{idProducto}`.
 *
 * Una receta es un conjunto de renglones, no un renglón: un azulito lleva alcohol,
 * curazao y limón. Editarla renglón por renglón deja estados intermedios visibles
 * —un producto a medio recomponer descontaría mal si alguien cobra en ese instante—
 * así que el conjunto entero viaja en una sola petición y se aplica en una
 * transacción.
 *
 * `insumos` puede llegar vacío: es como se borra la receta de un producto.
 * `distinct` sobre `id_insumo` porque el par producto+insumo es único en la base;
 * sin él, dos renglones del mismo insumo chocarían contra el índice con un 500 en
 * vez de un 422 que diga qué renglón está repetido.
 */
class ReemplazarRecetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();

        return [
            'insumos' => ['present', 'array', 'max:50'],
            'insumos.*.id_insumo' => [
                'required', 'integer', 'distinct',
                // Igual que en el alta de un renglón: un insumo de consumo no se
                // descuenta al vender, así que no puede formar parte de una receta.
                Rule::exists('insumos', 'id')->where(
                    fn ($q) => $q->where('id_establecimiento', $tenant)
                        ->whereNull('deleted_at')
                        ->where('tipo', 'controlado')
                ),
            ],
            'insumos.*.cantidad' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'insumos.present' => 'Falta la lista de insumos de la receta.',
            'insumos.max' => 'Una receta no puede tener más de 50 insumos.',
            'insumos.*.id_insumo.required' => 'Falta el insumo del renglón.',
            'insumos.*.id_insumo.distinct' => 'El insumo está repetido: súmalo en un solo renglón.',
            'insumos.*.id_insumo.exists' => 'El insumo no existe en este establecimiento, o es un insumo de consumo que no se descuenta por receta.',
            'insumos.*.cantidad.required' => 'Falta la cantidad del renglón.',
            'insumos.*.cantidad.gt' => 'La cantidad debe ser mayor que cero.',
        ];
    }
}
