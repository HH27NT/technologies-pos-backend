<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M11 · Reasignar el mesero de una orden (traspaso). El destino debe ser un usuario
 * ACTIVO del MISMO establecimiento del actor (tenant implícito, regla #4: no se recibe
 * id_establecimiento; se deriva del actor). La autorización (ordenes.reasignar) la
 * verifica la OrdenPolicy en el controlador.
 */
class ReasignarOrdenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->id_establecimiento;

        return [
            'id_usuario' => [
                'required', 'integer',
                Rule::exists('usuarios', 'id')
                    ->where('id_establecimiento', $tenantId)
                    ->where('activo', true)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'id_usuario.required' => 'Elige el mesero al que se reasignará la orden.',
            'id_usuario.exists' => 'El usuario seleccionado no es válido para este establecimiento.',
        ];
    }
}
