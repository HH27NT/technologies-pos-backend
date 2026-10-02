<?php

namespace App\Http\Requests;

use App\Domain\Usuarios\RolesAsignables;
use App\Support\Tenant\TenantContext;
use App\Support\Validation\PoliticaPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M04 · Alta de usuario. Unicidad de email/username POR establecimiento
 * (con el id_establecimiento del TenantContext, nunca del cliente).
 */
class CrearUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();

        return [
            'nombre' => ['required', 'string', 'max:100'],
            'email' => [
                'nullable', 'required_without:username', 'email', 'max:150',
                Rule::unique('usuarios', 'email')->where(fn ($q) => $q->where('id_establecimiento', $tenant)->whereNull('deleted_at')),
            ],
            'username' => [
                'nullable', 'required_without:email', 'string', 'max:50',
                Rule::unique('usuarios', 'username')->where(fn ($q) => $q->where('id_establecimiento', $tenant)->whereNull('deleted_at')),
            ],
            'password' => ['required', 'string', PoliticaPassword::regla()],
            'rol' => ['required', 'string', Rule::in(RolesAsignables::para($this->user()))],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe un usuario con ese correo.',
            'username.unique' => 'Ya existe un usuario con ese nombre de usuario.',
            'password.required' => 'La contraseña no cumple los requisitos.',
            'rol.in' => 'No puedes asignar ese rol.',
        ];
    }
}
