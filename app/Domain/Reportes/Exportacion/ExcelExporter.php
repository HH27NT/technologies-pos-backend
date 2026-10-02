<?php

namespace App\Domain\Reportes\Exportacion;

use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * M16 · Exportación a Excel (.xlsx) con Laravel Excel. Materializa el payload genérico
 * como una hoja de cálculo sin conocer el reporte concreto.
 */
class ExcelExporter implements ReporteExporter
{
    public function exportar(array $payload): string
    {
        return Excel::raw(new ReporteExcel($payload), ExcelFormat::XLSX);
    }

    public function extension(): string
    {
        return 'xlsx';
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
}
