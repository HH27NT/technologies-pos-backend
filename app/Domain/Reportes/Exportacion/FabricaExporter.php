<?php

namespace App\Domain\Reportes\Exportacion;

use InvalidArgumentException;

/**
 * M16 · Resuelve el exporter según el formato solicitado (`pdf`/`excel`). Punto único
 * de alta de formatos de exportación.
 */
class FabricaExporter
{
    public function para(string $formato): ReporteExporter
    {
        return match ($formato) {
            'pdf' => new PdfExporter,
            'excel', 'xlsx' => new ExcelExporter,
            default => throw new InvalidArgumentException("Formato de exportación no soportado: {$formato}"),
        };
    }

    /** @return list<string> formatos válidos (para Rule::in). */
    public static function formatos(): array
    {
        return ['pdf', 'excel'];
    }
}
