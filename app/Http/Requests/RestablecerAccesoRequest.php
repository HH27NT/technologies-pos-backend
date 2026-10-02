<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M02 · Restablecer el acceso del ADMIN de un establecimiento (rescate de plataforma).
 * El super_admin genera una contraseña temporal para un admin bloqueado. La
 * autorización (super_admin) la resuelve la policy en el controller; que el usuario
 * pertenezca al establecimiento y tenga rol admin lo valida el service.
 */
class RestablecerAccesoRequest extends FormRequest
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
            'id_usuario.required' => 'Selecciona un administrador.',
            'id_usuario.exists' => 'El usuario no existe.',
        ];
    }
}
