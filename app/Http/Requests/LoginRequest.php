<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M01 · Login. Acepta email o username en el campo `login`.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required' => 'Ingresa tu usuario y contraseña.',
            'password.required' => 'Ingresa tu usuario y contraseña.',
        ];
    }
}
