<?php

namespace App\Http\Requests;

use App\Domain\Autorizaciones\TipoAutorizacion;
use App\Support\Rules\PinNoTrivial;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

/**
 * M14.1 · Alta/cambio del PIN de autorización propio (`PUT /mi-pin`).
 *
 * Solo puede fijar PIN quien pueda AUTORIZAR algo (tiene alguno de los 4 permisos sensibles);
 * a nadie más le serviría. Y se exige la contraseña de login actual: sin eso, cualquiera que
 * encuentre una sesión abierta podría fijarse un PIN y con él autorizar overrides a voluntad.
 */
class GuardarPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usuario = $this->user();

        // El super admin no pertenece a ningún establecimiento: no hay membresía donde colgar
        // el PIN (y su Gate::before haría pasar cualquier permiso).
        if ($usuario === null || $usuario->esSuperAdmin()) {
            return false;
        }

        return collect(TipoAutorizacion::permisosAutorizador())
            ->contains(fn (string $permiso) => $usuario->can($permiso));
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'digits:6', 'confirmed', new PinNoTrivial],
            'password_actual' => ['required', 'string'],
        ];
    }

    /** La contraseña de login se verifica aparte para responder 422 con el resto de errores. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('password_actual')) {
                    return;
                }

                if (! Hash::check((string) $this->input('password_actual'), $this->user()->getAuthPassword())) {
                    $validator->errors()->add('password_actual', 'La contraseña actual no es correcta.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'pin.required' => 'El PIN es obligatorio.',
            'pin.digits' => 'El PIN debe tener exactamente 6 dígitos.',
            'pin.confirmed' => 'La confirmación del PIN no coincide.',
            'password_actual.required' => 'Debes confirmar tu contraseña actual.',
        ];
    }
}
