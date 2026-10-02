<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M16 · Validación de FORMA del rango de un reporte. La interpretación en la zona
 * horaria del establecimiento y la conversión a UTC viven en RangoFechas (dominio), no
 * aquí. `desde`/`hasta` son fechas (Y-m-d); si se envían, `hasta` no puede ser anterior
 * a `desde`. El preset es opcional (por defecto "hoy").
 */
class RangoReporteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset' => ['sometimes', 'string', 'in:hoy,semana,mes,anio,ano,año'],
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ];
    }

    public function messages(): array
    {
        return [
            'preset.in' => 'El periodo debe ser hoy, semana, mes o año.',
            'desde.date_format' => 'La fecha "desde" debe tener el formato AAAA-MM-DD.',
            'hasta.date_format' => 'La fecha "hasta" debe tener el formato AAAA-MM-DD.',
            'hasta.after_or_equal' => 'La fecha "hasta" no puede ser anterior a "desde".',
        ];
    }
}
