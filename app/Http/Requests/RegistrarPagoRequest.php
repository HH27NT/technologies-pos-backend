<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M12 · Registro de un pago. Forma: tipo de pago válido (global), monto > 0 y
 * `referencia` opcional (clave de idempotencia por intento, D1). El estado de la orden,
 * el saldo y la regla de sobrepago son de ESTADO (RegistrarPagoService).
 */
class RegistrarPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_tipo_pago' => ['required', 'integer', 'exists:tipos_pago,id'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'referencia' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_tipo_pago.required' => 'El tipo de pago es obligatorio.',
            'id_tipo_pago.exists' => 'El tipo de pago no es válido.',
            'monto.required' => 'El monto es obligatorio.',
            'monto.gt' => 'El monto debe ser mayor que cero.',
        ];
    }
}
