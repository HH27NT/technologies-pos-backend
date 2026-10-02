<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Ordenes\EstadoOrden;
use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\RangoFechas;
use App\Models\Orden;

/**
 * M16 · Ventas por mes. Igual que VentasQuery pero agrupando por mes local (Y-m) en vez
 * de por día: para la tendencia anual del dashboard (pedir con preset=anio da los meses
 * del año). Operativo: ambos roles, acotado al turno del operador (P21).
 */
class VentasMensualesQuery implements ReporteQuery
{
    public function ejecutar(RangoFechas $rango, AlcanceReporte $alcance): array
    {
        $q = Orden::query()
            ->where('estado', EstadoOrden::Pagada->value)
            ->where('cerrada_at', '>=', $rango->inicioUtc)
            ->where('cerrada_at', '<', $rango->finUtc);
        $alcance->aplicarAOrdenes($q);

        $ordenes = $q->get(['total', 'cerrada_at']);

        $filas = $ordenes
            ->groupBy(fn (Orden $o) => substr($rango->fechaLocal($o->cerrada_at), 0, 7))
            ->map(fn ($g, $mes) => [
                'mes' => $mes,
                'ordenes' => $g->count(),
                'total' => round($g->sum(fn ($o) => (float) $o->total), 2),
            ])
            ->sortKeys()
            ->values()
            ->all();

        return [
            'reporte' => 'ventas-mensuales',
            'titulo' => 'Ventas por mes',
            'rango' => $rango->meta(),
            'columnas' => ['Mes', 'Órdenes', 'Total'],
            'filas' => $filas,
            'resumen' => [
                'ordenes' => $ordenes->count(),
                'total' => round($ordenes->sum(fn ($o) => (float) $o->total), 2),
            ],
        ];
    }
}
