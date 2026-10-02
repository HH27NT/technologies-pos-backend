<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\RangoFechas;
use App\Models\Auditoria;
use App\Support\Tenant\TenantContext;

/**
 * M16 · Cancelaciones (solo ADMIN): renglones cancelados y órdenes anuladas del rango.
 * La fuente es la bitácora `auditoria` (usuario, motivo, fecha, entidad), que ya
 * registra `orden.item_cancelado` y `orden.anulada` con su motivo. Como `auditoria` no
 * usa TenantScope (id_establecimiento nullable = super_admin), se filtra por tenant
 * explícitamente.
 */
class CancelacionesQuery implements ReporteQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function ejecutar(RangoFechas $rango, AlcanceReporte $alcance): array
    {
        $eventos = Auditoria::query()
            ->where('id_establecimiento', $this->tenant->id())
            ->whereIn('accion', ['orden.item_cancelado', 'orden.anulada'])
            ->where('created_at', '>=', $rango->inicioUtc)
            ->where('created_at', '<', $rango->finUtc)
            ->with('usuario')
            ->orderByDesc('created_at')
            ->get();

        $filas = $eventos->map(fn (Auditoria $a) => [
            'fecha' => $rango->fechaHoraLocal($a->created_at),
            'accion' => $a->accion === 'orden.anulada' ? 'Orden anulada' : 'Renglón cancelado',
            'usuario' => $a->usuario?->nombre ?? '—',
            'entidad' => $a->entidad,
            'entidad_id' => $a->entidad_id,
            'motivo' => $a->datos_despues['motivo'] ?? null,
        ])->all();

        return [
            'reporte' => 'cancelaciones',
            'titulo' => 'Reporte de cancelaciones',
            'rango' => $rango->meta(),
            'columnas' => ['Fecha', 'Acción', 'Usuario', 'Entidad', 'ID', 'Motivo'],
            'filas' => $filas,
            'resumen' => [
                'total' => $eventos->count(),
                'items_cancelados' => $eventos->where('accion', 'orden.item_cancelado')->count(),
                'ordenes_anuladas' => $eventos->where('accion', 'orden.anulada')->count(),
            ],
        ];
    }
}
