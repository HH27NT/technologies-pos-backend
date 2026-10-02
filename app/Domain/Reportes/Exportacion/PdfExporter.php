<?php

namespace App\Domain\Reportes\Exportacion;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * M16 · Exportación a PDF con dompdf. Renderiza cualquier reporte con la vista genérica
 * `reportes.pdf` a partir de `columnas`/`filas`/`resumen`.
 */
class PdfExporter implements ReporteExporter
{
    public function exportar(array $payload): string
    {
        return Pdf::loadView('reportes.pdf', ['reporte' => $payload])->output();
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function contentType(): string
    {
        return 'application/pdf';
    }
}
