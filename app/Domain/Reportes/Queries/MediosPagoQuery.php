<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\RangoFechas;
use App\Models\Pago;

/**
 * M16 · Medios de pago. Agrupa los pagos del rango por tipo (efectivo, tarjeta,
 * transferencia): número de operaciones y monto. Insumo del arqueo (separa efectivo de
 * los demás, P6). El OPERADOR ve solo los pagos de sus sesiones (P21).
 */
class MediosPagoQuery implements ReporteQuery
{
    public function ejecutar(RangoFechas $rango, AlcanceReporte $alcance): array
    {
        $q = Pago::query()
            ->where('pagado_at', '>=', $rango->inicioUtc)
            ->where('pagado_at', '<', $rango->finUtc)
            ->with('tipoPago');

        if ($alcance->limitado) {
            $q->whereHas('orden', fn ($o) => $o->whereIn('id_sesion_caja', $alcance->sesionesPermitidas ?: [0]));
        }

        $pagos = $q->get(['id', 'id_orden', 'id_tipo_pago', 'monto']);

        $filas = $pagos
            ->groupBy(fn (Pago $p) => $p->tipoPago?->nombre ?? '—')
            ->map(fn ($g, $medio) => [
                'medio' => $medio,
                'operaciones' => $g->count(),
                'monto' => round($g->sum(fn ($p) => (float) $p->monto), 2),
            ])
            ->sortByDesc('monto')
            ->values()
            ->all();

        return [
            'reporte' => 'medios-pago',
            'titulo' => 'Reporte de medios de pago',
            'rango' => $rango->meta(),
            'columnas' => ['Medio', 'Operaciones', 'Monto'],
            'filas' => $filas,
            'resumen' => [
                'operaciones' => $pagos->count(),
                'monto' => round($pagos->sum(fn ($p) => (float) $p->monto), 2),
            ],
        ];
    }
}
