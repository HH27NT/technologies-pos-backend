<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M02 · Promover a ADMIN a un usuario existente del establecimiento.
 */
class AsignarAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_usuario' => ['required', 'integer', 'exists:usuarios,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_usuario.required' => 'Selecciona un usuario.',
            'id_usuario.exists' => 'El usuario no existe.',
        ];
    }
}
