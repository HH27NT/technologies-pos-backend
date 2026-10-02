<?php

namespace App\Http\Requests;

use App\Domain\Reportes\Exportacion\FabricaExporter;
use App\Domain\Reportes\TipoReporte;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M16 · Validación de la exportación de un reporte: qué reporte y en qué formato,
 * además del rango (mismas reglas de forma que RangoReporteRequest). El alcance por rol
 * (P21) y la autorización se resuelven en el controlador/policy, no aquí.
 */
class ExportarReporteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reporte' => ['required', Rule::in(TipoReporte::valores())],
            'formato' => ['required', Rule::in(FabricaExporter::formatos())],
            'preset' => ['sometimes', 'string', 'in:hoy,semana,mes,anio,ano,año'],
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ];
    }

    public function messages(): array
    {
        return [
            'reporte.required' => 'El reporte a exportar es obligatorio.',
            'reporte.in' => 'El reporte solicitado no existe.',
            'formato.required' => 'El formato de exportación es obligatorio.',
            'formato.in' => 'El formato debe ser PDF o Excel.',
        ];
    }
}
