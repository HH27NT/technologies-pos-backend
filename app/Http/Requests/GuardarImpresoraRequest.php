<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M13 · Alta/edición de impresora. El tipo está acotado al ENUM del DER V1.2.
 */
class GuardarImpresoraRequest extends FormRequest
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
            'tipo' => [...$obligatorio, Rule::in(['ticket', 'barra', 'cocina', 'admin'])],
            'conexion' => ['sometimes', 'nullable', 'string', 'max:150'],
            'activa' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la impresora es obligatorio.',
            'tipo.required' => 'El tipo de impresora es obligatorio.',
            'tipo.in' => 'Tipo de impresora inválido.',
        ];
    }
}
