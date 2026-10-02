<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M10 · Cierre de caja con arqueo. El cliente captura el efectivo contado y, si hay
 * diferencia, el motivo. La obligatoriedad real del motivo depende de la diferencia
 * (monto_contado − monto_sistema), que se calcula en servidor: esa regla vive en
 * CerrarCajaService, no aquí. En forma, el motivo es opcional pero acotado.
 */
class CerrarCajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'monto_contado' => ['required', 'numeric', 'min:0'],
            'motivo' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'monto_contado.required' => 'El monto contado es obligatorio.',
            'monto_contado.min' => 'El monto contado no puede ser negativo.',
        ];
    }
}
