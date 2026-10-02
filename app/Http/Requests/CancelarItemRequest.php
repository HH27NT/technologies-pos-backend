<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M11 · Cancelación de un renglón. El ADMIN con permiso directo (`ordenes.cancelar_item`)
 * la ejecuta como siempre (motivo opcional). El OPERADOR sin permiso debe adjuntar el
 * bloque de override (M14.1: PIN de 6 dígitos de un autorizador + motivo); esos campos solo
 * son obligatorios cuando NO hay permiso directo (retrocompatible). El motivo se registra en
 * auditoría (detalle_orden no tiene columna motivo).
 */
class CancelarItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $requiereOverride = ! $this->user()->can('ordenes.cancelar_item');

        return [
            'motivo' => [Rule::requiredIf($requiereOverride), 'nullable', 'string', 'max:500'],
            'autorizacion_pin' => [Rule::requiredIf($requiereOverride), 'nullable', 'digits:6'],
            'terminal' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'El motivo es obligatorio para autorizar esta operación.',
            'autorizacion_pin.required' => 'El PIN de autorización es obligatorio.',
            'autorizacion_pin.digits' => 'El PIN de autorización debe tener 6 dígitos.',
        ];
    }
}
