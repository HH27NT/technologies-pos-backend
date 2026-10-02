<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M03 · Edición de la configuración del establecimiento (datos de ticket,
 * impresión automática, stock mínimo global e impuesto P11). Solo validación
 * de FORMA; la de ESTADO de negocio (impuesto activo ⇒ tasa > 0) vive en
 * ActualizarConfiguracionService (Convenciones §17, DoD §7).
 */
class ActualizarConfiguracionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre_comercial' => ['sometimes', 'nullable', 'string', 'max:150'],
            'telefono_ticket' => ['sometimes', 'nullable', 'string', 'max:20'],
            'direccion_ticket' => ['sometimes', 'nullable', 'string'],
            'impresion_automatica' => ['sometimes', 'boolean'],
            'terminal_compartida' => ['sometimes', 'boolean'],
            // Cota inferior de 30 s: por debajo, la tablet se bloquea mientras el mesero
            // captura la orden y el modo se vuelve inusable. Cota superior de 1 h: más allá
            // el auto-bloqueo deja de proteger nada.
            'bloqueo_terminal_segundos' => ['sometimes', 'integer', 'min:30', 'max:3600'],
            'stock_minimo_global' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'aplica_impuesto' => ['sometimes', 'boolean'],
            'tasa_impuesto' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'tasa_impuesto.min' => 'La tasa de impuesto no puede ser negativa.',
            'tasa_impuesto.max' => 'La tasa de impuesto no puede superar 100.',
            'stock_minimo_global.min' => 'El stock mínimo no puede ser negativo.',
            'bloqueo_terminal_segundos.min' => 'El bloqueo de terminal no puede ser menor a 30 segundos.',
            'bloqueo_terminal_segundos.max' => 'El bloqueo de terminal no puede superar una hora.',
        ];
    }
}
