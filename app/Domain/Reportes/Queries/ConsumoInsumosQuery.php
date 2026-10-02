<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Inventario\TipoMovimiento;
use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\RangoFechas;
use App\Models\MovimientoInventario;

/**
 * M16 · Insumos más utilizados (solo ADMIN). Suma la cantidad consumida por insumo en
 * el rango: los movimientos que representan USO (venta por receta, salida y consumo
 * interno), no las entradas ni los ajustes. Top 10 por cantidad.
 */
class ConsumoInsumosQuery implements ReporteQuery
{
    /** Tipos de movimiento que cuentan como consumo/uso del insumo. */
    private const CONSUMO = [
        TipoMovimiento::Venta->value,
        TipoMovimiento::Salida->value,
        TipoMovimiento::ConsumoInterno->value,
    ];

    public function ejecutar(RangoFechas $rango, AlcanceReporte $alcance): array
    {
        $movimientos = MovimientoInventario::query()
            ->whereIn('tipo', self::CONSUMO)
            ->where('created_at', '>=', $rango->inicioUtc)
            ->where('created_at', '<', $rango->finUtc)
            ->with(['insumo.unidadMedida'])
            ->get();

        $filas = $movimientos
            ->groupBy('id_insumo')
            ->map(fn ($g) => [
                'insumo' => $g->first()->insumo?->nombre ?? '—',
                'cantidad' => round($g->sum(fn ($m) => (float) $m->cantidad), 3),
                'unidad' => $g->first()->insumo?->unidadMedida?->abreviatura
                    ?? $g->first()->insumo?->unidadMedida?->nombre ?? '—',
            ])
            ->sortByDesc('cantidad')
            ->take(10)
            ->values()
            ->all();

        return [
            'reporte' => 'consumo-insumos',
            'titulo' => 'Insumos más utilizados',
            'rango' => $rango->meta(),
            'columnas' => ['Insumo', 'Cantidad', 'Unidad'],
            'filas' => $filas,
            'resumen' => [
                'insumos' => count($filas),
                'movimientos' => $movimientos->count(),
            ],
        ];
    }
}
