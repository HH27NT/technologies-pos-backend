<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M11 · Descuento de orden (importe). Forma: monto ≥ 0. El tope "no excede el
 * subtotal" depende de los renglones (estado): vive en AplicarDescuentoService (422).
 */
class AplicarDescuentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descuento' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'descuento.required' => 'El descuento es obligatorio.',
            'descuento.min' => 'El descuento no puede ser negativo.',
        ];
    }
}
