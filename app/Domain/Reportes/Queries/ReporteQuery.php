<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\RangoFechas;

/**
 * M16 · Contrato de un reporte de SOLO LECTURA. Devuelve un payload homogéneo
 * (`columnas`/`filas`/`resumen`) que sirve tanto a la respuesta interactiva como al
 * exportador (que renderiza cualquier reporte de forma genérica). Un Query Service NO
 * muta estado ni abre transacciones de escritura.
 */
interface ReporteQuery
{
    public function ejecutar(RangoFechas $rango, AlcanceReporte $alcance): array;
}
