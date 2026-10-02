<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M02 · Edición de datos base de un establecimiento.
 */
class ActualizarEstablecimientoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:150'],
            'razon_social' => ['nullable', 'string', 'max:150'],
            'rfc' => ['nullable', 'string', 'min:12', 'max:13'],
            'direccion' => ['nullable', 'string'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'logo_url' => ['nullable', 'string', 'max:255'],
            'zona_horaria' => ['nullable', 'string', 'max:50'],
            'moneda' => ['nullable', 'string', 'size:3'],
        ];
    }

    public function messages(): array
    {
        return [
            'rfc.min' => 'RFC inválido.',
            'rfc.max' => 'RFC inválido.',
            'email.email' => 'Correo inválido.',
        ];
    }
}
