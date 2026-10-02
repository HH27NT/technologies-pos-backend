<?php

namespace App\Http\Requests;

use App\Support\Rules\PinNoTrivial;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta/cambio del PIN de un mesero, hecho por quien administra el personal.
 *
 * A diferencia de `GuardarPinRequest` (M14.1) **no se exige la contraseña del actor**. Allí se
 * pide porque el PIN de autorización aprueba dinero y una sesión abierta olvidada bastaría para
 * fabricarse uno. Aquí el PIN solo identifica: quien lo conozca puede atribuirse una venta, no
 * autorizar nada, y quien lo fija ya podía reasignar órdenes. Pedir contraseña añadiría fricción
 * a una tarea rutinaria (dar de alta al personal del turno) sin cerrar ningún hueco real.
 *
 * La autorización sobre el usuario objetivo la resuelve `UsuarioPolicy::update` en el
 * controlador: así un gerente no puede fijarle PIN a un admin, igual que no puede editarlo.
 */
class FijarMeseroPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('usuarios.gestionar');
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'digits:6', new PinNoTrivial],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.required' => 'El PIN es obligatorio.',
            'pin.digits' => 'El PIN debe tener exactamente 6 dígitos.',
        ];
    }
}
