<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M10 · Apertura de caja. Solo el fondo inicial; el resto del ciclo lo fija el
 * servidor (usuario, fecha, estado). monto_inicial ≥ 0.
 */
class AbrirCajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'monto_inicial' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'monto_inicial.required' => 'El monto inicial es obligatorio.',
            'monto_inicial.min' => 'El monto inicial no puede ser negativo.',
        ];
    }
}
