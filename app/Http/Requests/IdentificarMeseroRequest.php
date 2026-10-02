<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Identificación del mesero por PIN en la terminal compartida.
 *
 * Exige `ordenes.crear` porque esto solo tiene sentido desde el POS: es la cuenta de terminal
 * —ya autenticada— la que pregunta "¿quién está tecleando?". **No es un login**: no emite token
 * ni cambia la sesión (ver `ResolverMeseroPorPinService`).
 *
 * No se valida `PinNoTrivial` aquí: eso se exige al *fijar* el PIN. Al identificar, un PIN
 * trivial simplemente no resolverá a nadie.
 */
class IdentificarMeseroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ordenes.crear');
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'digits:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.required' => 'Teclea tu PIN.',
            'pin.digits' => 'El PIN debe tener exactamente 6 dígitos.',
        ];
    }
}
