<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Ordenes\EstadoOrden;
use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\RangoFechas;
use App\Models\DetalleOrden;
use App\Models\Orden;

/**
 * M16 · Dashboard del día. Tarjetas (ventas, órdenes abiertas/pagadas) y top de
 * productos vendidos, dentro del rango (por defecto "hoy" en la zona del establecimiento).
 * El OPERADOR ve solo su turno (P21, vía AlcanceReporte).
 */
class DashboardQuery implements ReporteQuery
{
    public function ejecutar(RangoFechas $rango, AlcanceReporte $alcance): array
    {
        $pagadasQ = Orden::query()
            ->where('estado', EstadoOrden::Pagada->value)
            ->where('cerrada_at', '>=', $rango->inicioUtc)
            ->where('cerrada_at', '<', $rango->finUtc);
        $alcance->aplicarAOrdenes($pagadasQ);
        $pagadas = $pagadasQ->get(['id', 'total']);

        $abiertasQ = Orden::query()->where('estado', EstadoOrden::Abierta->value);
        $alcance->aplicarAOrdenes($abiertasQ);

        $top = DetalleOrden::query()
            ->whereIn('id_orden', $pagadas->pluck('id'))
            ->where('estado_item', 'activo')
            ->with('producto')
            ->get()
            ->groupBy('id_producto')
            ->map(fn ($g) => [
                'producto' => $g->first()->producto?->nombre ?? '—',
                'cantidad' => round($g->sum(fn ($d) => (float) $d->cantidad), 3),
                'total' => round($g->sum(fn ($d) => (float) $d->subtotal), 2),
            ])
            ->sortByDesc('cantidad')
            ->take(10)
            ->values()
            ->all();

        return [
            'reporte' => 'dashboard',
            'titulo' => 'Dashboard del día',
            'rango' => $rango->meta(),
            'columnas' => ['Producto', 'Cantidad', 'Total'],
            'filas' => $top,
            'resumen' => [
                'ventas_total' => round($pagadas->sum(fn ($o) => (float) $o->total), 2),
                'ordenes_pagadas' => $pagadas->count(),
                'ordenes_abiertas' => $abiertasQ->count(),
            ],
        ];
    }
}
