<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M01 · Solicitud de recuperación de contraseña por correo (CU-01 A1).
 * Solo valida la forma del correo; la resolución del usuario y el envío del
 * enlace los hace RecuperarPasswordService (validación de estado en el dominio).
 */
class RecuperarPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Ingresa tu correo.',
            'email.email' => 'Ingresa un correo válido.',
        ];
    }
}
