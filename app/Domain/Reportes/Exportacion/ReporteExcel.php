<?php

namespace App\Domain\Reportes\Exportacion;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * M16 · Hoja de cálculo de un reporte para Laravel Excel. Aplana el payload homogéneo
 * (`columnas` → encabezados, `filas` → renglones) de forma genérica.
 */
class ReporteExcel implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private readonly array $payload) {}

    public function array(): array
    {
        return array_map(fn ($fila) => array_values((array) $fila), $this->payload['filas'] ?? []);
    }

    public function headings(): array
    {
        return $this->payload['columnas'] ?? [];
    }

    public function title(): string
    {
        return $this->payload['titulo'] ?? 'Reporte';
    }
}
