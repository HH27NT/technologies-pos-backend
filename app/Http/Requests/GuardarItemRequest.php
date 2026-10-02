<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M11 · Alta de un renglón. Forma: producto del tenant y cantidad > 0. La
 * disponibilidad del producto y el estado de la orden son reglas de ESTADO
 * (AgregarItemService). El precio se congela en el servidor (precio_venta).
 */
class GuardarItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_producto' => ['required', 'integer', 'exists:productos,id'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'notas' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_producto.required' => 'El producto es obligatorio.',
            'id_producto.exists' => 'El producto no es válido.',
            'cantidad.required' => 'La cantidad es obligatoria.',
            'cantidad.gt' => 'La cantidad debe ser mayor que cero.',
        ];
    }
}
