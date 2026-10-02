<?php

namespace App\Http\Requests;

use App\Support\Tenant\TenantContext;
use App\Support\Validation\PoliticaPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M04 · Edición de usuario. Unicidad por tenant ignorando al propio usuario.
 */
class ActualizarUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();
        $idUsuario = $this->route('id');

        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => [
                'sometimes', 'nullable', 'email', 'max:150',
                Rule::unique('usuarios', 'email')->ignore($idUsuario)->where(fn ($q) => $q->where('id_establecimiento', $tenant)->whereNull('deleted_at')),
            ],
            'username' => [
                'sometimes', 'nullable', 'string', 'max:50',
                Rule::unique('usuarios', 'username')->ignore($idUsuario)->where(fn ($q) => $q->where('id_establecimiento', $tenant)->whereNull('deleted_at')),
            ],
            'password' => ['sometimes', 'nullable', 'string', PoliticaPassword::regla()],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe un usuario con ese correo.',
            'username.unique' => 'Ya existe un usuario con ese nombre de usuario.',
        ];
    }
}
