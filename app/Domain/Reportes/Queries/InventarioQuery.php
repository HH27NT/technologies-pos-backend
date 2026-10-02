<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Inventario\TipoMovimiento;
use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\RangoFechas;
use App\Models\Insumo;
use App\Models\MovimientoInventario;

/**
 * M16 · Inventario (solo ADMIN): stock actual, stock bajo, movimientos del rango y
 * mermas. El stock es una foto del cache (`stock_actual`, cuya verdad es el ledger). La
 * tabla exportable son los movimientos; el stock y el stock bajo viajan como secciones
 * extra del payload.
 */
class InventarioQuery implements ReporteQuery
{
    public function ejecutar(RangoFechas $rango, AlcanceReporte $alcance): array
    {
        $insumos = Insumo::query()->where('activo', true)->with('unidadMedida')->orderBy('nombre')->get();

        $stock = $insumos->map(fn (Insumo $i) => [
            'insumo' => $i->nombre,
            'stock_actual' => (float) $i->stock_actual,
            'stock_minimo' => $i->stock_minimo !== null ? (float) $i->stock_minimo : null,
            'unidad' => $i->unidadMedida?->abreviatura ?? $i->unidadMedida?->nombre ?? '—',
        ])->all();

        $stockBajo = array_values(array_filter(
            $stock,
            fn ($i) => $i['stock_minimo'] !== null && $i['stock_actual'] <= $i['stock_minimo'],
        ));

        $movimientos = MovimientoInventario::query()
            ->where('created_at', '>=', $rango->inicioUtc)
            ->where('created_at', '<', $rango->finUtc)
            ->with(['insumo', 'usuario'])
            ->orderByDesc('created_at')
            ->get();

        $filas = $movimientos->map(fn (MovimientoInventario $m) => [
            'fecha' => $rango->fechaHoraLocal($m->created_at),
            'insumo' => $m->insumo?->nombre ?? '—',
            'tipo' => $m->tipo,
            'cantidad' => (float) $m->cantidad,
            'stock_resultante' => $m->stock_resultante !== null ? (float) $m->stock_resultante : null,
            'usuario' => $m->usuario?->nombre ?? '—',
            'motivo' => $m->motivo,
        ])->all();

        $mermas = $movimientos->whereIn('tipo', [TipoMovimiento::Merma->value, TipoMovimiento::Rotura->value]);

        return [
            'reporte' => 'inventario',
            'titulo' => 'Reporte de inventario',
            'rango' => $rango->meta(),
            'columnas' => ['Fecha', 'Insumo', 'Tipo', 'Cantidad', 'Stock resultante', 'Usuario', 'Motivo'],
            'filas' => $filas,
            'stock' => $stock,
            'stock_bajo' => $stockBajo,
            'resumen' => [
                'insumos' => count($stock),
                'stock_bajo' => count($stockBajo),
                'movimientos' => $movimientos->count(),
                'mermas' => $mermas->count(),
                'cantidad_mermada' => round($mermas->sum(fn ($m) => (float) $m->cantidad), 3),
            ],
        ];
    }
}
