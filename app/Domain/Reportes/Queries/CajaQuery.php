<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\RangoFechas;
use App\Models\SesionCaja;

/**
 * M16 · Caja: aperturas, cierres y diferencias (con motivo, P5) del rango. El
 * `monto_sistema` ya viene calculado solo con efectivo (P6/P7) desde el cierre. El
 * OPERADOR ve solo sus propias sesiones (P21).
 */
class CajaQuery implements ReporteQuery
{
    public function ejecutar(RangoFechas $rango, AlcanceReporte $alcance): array
    {
        $q = SesionCaja::query()
            ->where('abierta_at', '>=', $rango->inicioUtc)
            ->where('abierta_at', '<', $rango->finUtc)
            ->with(['usuarioApertura', 'usuarioCierre'])
            ->orderByDesc('abierta_at');
        $alcance->aplicarASesiones($q);

        $sesiones = $q->get();

        $filas = $sesiones->map(fn (SesionCaja $s) => [
            'apertura' => $rango->fechaHoraLocal($s->abierta_at),
            'cierre' => $rango->fechaHoraLocal($s->cerrada_at),
            'usuario_apertura' => $s->usuarioApertura?->nombre ?? '—',
            'estado' => $s->estado,
            'monto_inicial' => (float) $s->monto_inicial,
            'monto_sistema' => $s->monto_sistema !== null ? (float) $s->monto_sistema : null,
            'monto_contado' => $s->monto_contado !== null ? (float) $s->monto_contado : null,
            'diferencia' => $s->diferencia !== null ? (float) $s->diferencia : null,
            'motivo' => $s->motivo,
        ])->all();

        return [
            'reporte' => 'caja',
            'titulo' => 'Reporte de caja',
            'rango' => $rango->meta(),
            'columnas' => ['Apertura', 'Cierre', 'Usuario', 'Estado', 'Inicial', 'Sistema', 'Contado', 'Diferencia', 'Motivo'],
            'filas' => $filas,
            'resumen' => [
                'sesiones' => $sesiones->count(),
                'diferencia_total' => round($sesiones->sum(fn ($s) => (float) $s->diferencia), 2),
            ],
        ];
    }
}
