<?php

namespace App\Domain\Reportes\Exportacion;

/**
 * M16 · Contrato de exportación de un reporte. Aísla la librería concreta (dompdf,
 * Laravel Excel) del dominio: el Query Service produce el payload homogéneo y el
 * exporter lo materializa en bytes. Añadir un formato nuevo es implementar esta interfaz.
 */
interface ReporteExporter
{
    /** Devuelve el contenido binario del archivo a partir del payload del reporte. */
    public function exportar(array $payload): string;

    /** Extensión del archivo generado (p. ej. `pdf`, `xlsx`). */
    public function extension(): string;

    /** MIME del archivo generado (para la descarga). */
    public function contentType(): string;
}
