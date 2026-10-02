<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M11 · Modificación de la cantidad de un renglón. Forma: cantidad > 0. La regla
 * "solo si enviado=false y activo" es de ESTADO (ModificarItemService).
 */
class ModificarItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cantidad' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'cantidad.required' => 'La cantidad es obligatoria.',
            'cantidad.gt' => 'La cantidad debe ser mayor que cero.',
        ];
    }
}
