<?php

namespace App\Domain\Caja\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Caja\Events\CajaCerrada;
use App\Models\Pago;
use App\Models\SesionCaja;
use App\Support\Exceptions\CajaConOrdenesAbiertasException;
use App\Support\Exceptions\MotivoDiferenciaRequeridoException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * M10 · Cierre de la sesión de caja con arqueo de efectivo. Atómico y auditado (§15).
 *
 * Arqueo SOLO efectivo (P6): monto_sistema = monto_inicial + Σ pagos en efectivo de
 * las órdenes de la sesión. Sin retiros/fondos (P7). Como `pagos` está vacío hasta el
 * Sprint 8, la suma da 0 hoy; la consulta queda cableada para cuando Pagos la alimente.
 *
 * diferencia = monto_contado − monto_sistema; si ≠ 0 el motivo es obligatorio (P5).
 * El cierre NO requiere autorización en este sprint.
 */
class CerrarCajaService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function cerrar(SesionCaja $sesion, array $datos): SesionCaja
    {
        return DB::transaction(function () use ($sesion, $datos) {
            $sesion = SesionCaja::whereKey($sesion->id)->lockForUpdate()->firstOrFail();

            if ($sesion->ordenes()->where('estado', 'abierta')->exists()) {
                throw new CajaConOrdenesAbiertasException;
            }

            $montoSistema = (float) $sesion->monto_inicial + $this->efectivoDeLaSesion($sesion);
            $montoContado = (float) $datos['monto_contado'];
            $diferencia = round($montoContado - $montoSistema, 2);
            $motivo = $datos['motivo'] ?? null;

            if ($diferencia != 0.0 && ($motivo === null || trim($motivo) === '')) {
                throw new MotivoDiferenciaRequeridoException;
            }

            $sesion->fill([
                'id_usuario_cierre' => Auth::id(),
                'monto_sistema' => $montoSistema,
                'monto_contado' => $montoContado,
                'diferencia' => $diferencia,
                'motivo' => $diferencia != 0.0 ? $motivo : null,
                'estado' => 'cerrada',
                'cerrada_at' => now(),
            ])->save();

            $this->auditoria->registrar(
                accion: 'caja.cerrada',
                entidad: 'sesiones_caja',
                entidadId: $sesion->id,
                datosDespues: [
                    'monto_sistema' => $montoSistema,
                    'monto_contado' => $montoContado,
                    'diferencia' => $diferencia,
                    'motivo' => $sesion->motivo,
                    'id_usuario_cierre' => $sesion->id_usuario_cierre,
                ],
            );

            event(new CajaCerrada($sesion));

            return $sesion;
        });
    }

    /** Σ de los pagos en efectivo de las órdenes de la sesión (P6). 0 hasta el Sprint 8. */
    private function efectivoDeLaSesion(SesionCaja $sesion): float
    {
        return (float) Pago::query()
            ->whereHas('orden', fn ($q) => $q->where('id_sesion_caja', $sesion->id))
            ->whereHas('tipoPago', fn ($q) => $q->where('nombre', 'efectivo'))
            ->sum('monto');
    }
}
