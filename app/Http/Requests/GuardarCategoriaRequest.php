<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M05 · Alta/edición de categoría de producto. En PUT los campos son parciales
 * (sometimes); el aislamiento por tenant lo aplica BelongsToTenant al crear.
 */
class GuardarCategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $obligatorio = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'nombre' => [...$obligatorio, 'string', 'max:80'],
            'orden_display' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la categoría es obligatorio.',
        ];
    }
}
