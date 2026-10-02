<?php

namespace App\Http\Requests;

use App\Domain\Usuarios\RolesAsignables;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M04 · Cambio de rol de un usuario (admin/gerente/operador/mesero). Los roles que el
 * actor puede asignar dependen de sus permisos (RolesAsignables): el gerente no puede
 * asignar `admin`. Reasignar el rol de un usuario que YA es admin lo bloquea UsuarioPolicy.
 */
class AsignarRolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rol' => ['required', 'string', Rule::in(RolesAsignables::para($this->user()))],
        ];
    }

    public function messages(): array
    {
        return [
            'rol.required' => 'Selecciona un rol.',
            'rol.in' => 'No puedes asignar ese rol.',
        ];
    }
}
