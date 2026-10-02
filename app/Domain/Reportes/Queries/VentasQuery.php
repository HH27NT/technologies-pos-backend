<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Ordenes\EstadoOrden;
use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\RangoFechas;
use App\Models\Orden;

/**
 * M16 · Ventas por periodo. Suma las órdenes `pagada` cuyo cierre (`cerrada_at`) cae en
 * el rango, desglosadas por día local. El impuesto se reporta APARTE (P11). El
 * agrupamiento por día se hace en PHP con la zona del establecimiento (paridad
 * SQLite/PostgreSQL, sin funciones de fecha específicas del motor).
 */
class VentasQuery implements ReporteQuery
{
    public function ejecutar(RangoFechas $rango, AlcanceReporte $alcance): array
    {
        $q = Orden::query()
            ->where('estado', EstadoOrden::Pagada->value)
            ->where('cerrada_at', '>=', $rango->inicioUtc)
            ->where('cerrada_at', '<', $rango->finUtc);
        $alcance->aplicarAOrdenes($q);

        $ordenes = $q->get(['subtotal', 'descuento', 'impuesto', 'total', 'cerrada_at']);

        $filas = $ordenes
            ->groupBy(fn (Orden $o) => $rango->fechaLocal($o->cerrada_at))
            ->map(fn ($g, $fecha) => [
                'fecha' => $fecha,
                'ordenes' => $g->count(),
                'subtotal' => round($g->sum(fn ($o) => (float) $o->subtotal), 2),
                'descuento' => round($g->sum(fn ($o) => (float) $o->descuento), 2),
                'impuesto' => round($g->sum(fn ($o) => (float) $o->impuesto), 2),
                'total' => round($g->sum(fn ($o) => (float) $o->total), 2),
            ])
            ->sortKeys()
            ->values()
            ->all();

        return [
            'reporte' => 'ventas',
            'titulo' => 'Reporte de ventas',
            'rango' => $rango->meta(),
            'columnas' => ['Fecha', 'Órdenes', 'Subtotal', 'Descuento', 'Impuesto', 'Total'],
            'filas' => $filas,
            'resumen' => [
                'ordenes' => $ordenes->count(),
                'subtotal' => round($ordenes->sum(fn ($o) => (float) $o->subtotal), 2),
                'descuento' => round($ordenes->sum(fn ($o) => (float) $o->descuento), 2),
                'impuesto' => round($ordenes->sum(fn ($o) => (float) $o->impuesto), 2),
                'total' => round($ordenes->sum(fn ($o) => (float) $o->total), 2),
            ],
        ];
    }
}
