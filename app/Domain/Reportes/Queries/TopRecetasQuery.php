<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\RangoFechas;
use App\Models\RecetaProducto;

/**
 * M16 · Insumos que aparecen en más recetas (solo ADMIN). Cuenta en cuántos productos
 * distintos participa cada insumo como parte de su receta (BOM). Es una métrica
 * estructural del catálogo: no depende del rango de fechas. Top 10.
 */
class TopRecetasQuery implements ReporteQuery
{
    public function ejecutar(RangoFechas $rango, AlcanceReporte $alcance): array
    {
        $recetas = RecetaProducto::query()->with('insumo')->get();

        $filas = $recetas
            ->groupBy('id_insumo')
            ->map(fn ($g) => [
                'insumo' => $g->first()->insumo?->nombre ?? '—',
                'recetas' => $g->pluck('id_producto')->unique()->count(),
            ])
            ->sortByDesc('recetas')
            ->take(10)
            ->values()
            ->all();

        return [
            'reporte' => 'top-recetas',
            'titulo' => 'Insumos en más recetas',
            'rango' => $rango->meta(),
            'columnas' => ['Insumo', 'Recetas'],
            'filas' => $filas,
            'resumen' => [
                'insumos' => count($filas),
                'recetas' => $recetas->pluck('id_producto')->unique()->count(),
            ],
        ];
    }
}
