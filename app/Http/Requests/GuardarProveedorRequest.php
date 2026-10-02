<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M08 · Alta/edición de proveedor. En PUT los campos son parciales (sometimes);
 * el aislamiento por tenant lo aplica BelongsToTenant al crear.
 */
class GuardarProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $obligatorio = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'nombre' => [...$obligatorio, 'string', 'max:120'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del proveedor es obligatorio.',
        ];
    }
}
