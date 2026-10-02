<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M08 · Alta/edición de unidad de medida PROPIA (P4). El id_establecimiento lo
 * autollena IncluyeGlobales al crear; las globales no llegan aquí (las bloquea
 * la policy y el servicio).
 */
class GuardarUnidadMedidaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $obligatorio = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'nombre' => [...$obligatorio, 'string', 'max:50'],
            'abreviacion' => ['sometimes', 'nullable', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la unidad es obligatorio.',
        ];
    }
}
