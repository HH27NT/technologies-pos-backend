<?php

namespace App\Domain\Autorizaciones\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Autorizaciones\EstadoAutorizacion;
use App\Domain\Autorizaciones\Events\AutorizacionResuelta;
use App\Domain\Autorizaciones\TipoAutorizacion;
use App\Domain\Inventario\Services\RegistrarMovimientoService;
use App\Domain\Ordenes\Services\AnularOrdenService;
use App\Domain\Ordenes\Services\CancelarItemService;
use App\Models\Autorizacion;
use App\Models\Orden;
use App\Support\Exceptions\AutorizacionYaResueltaException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * M14 · Resolución de una solicitud por el ADMIN (aprobar/rechazar). Atómico y auditado
 * (§15); resolución + ejecución del servicio destino + auditoría en una sola transacción.
 *
 * Idempotencia (regla global 22): una autorización ya resuelta no cambia de estado
 * (lockForUpdate + verificación → AutorizacionYaResueltaException).
 *
 * Al APROBAR, el sistema ejecuta el servicio destino reutilizado de S7/S5 como si lo
 * hiciera el admin (Auth::id() = admin en el movimiento/auditoría) y enlaza el resultado
 * vía id_autorizacion. Si el destino lanza una excepción de estado (p. ej. la orden dejó
 * de ser modificable), toda la transacción se revierte y la solicitud sigue `pendiente`.
 */
class ResolverAutorizacionService
{
    public function __construct(
        private readonly RegistrarAuditoriaService $auditoria,
        private readonly CancelarItemService $cancelarItem,
        private readonly AnularOrdenService $anularOrden,
        private readonly RegistrarMovimientoService $registrarMovimiento,
    ) {}

    public function aprobar(Autorizacion $autorizacion): Autorizacion
    {
        return DB::transaction(function () use ($autorizacion) {
            $autorizacion = $this->bloquearPendiente($autorizacion);

            $autorizacion->estado = EstadoAutorizacion::Aprobada->value;
            $autorizacion->id_usuario_autoriza = Auth::id();
            $autorizacion->resuelta_at = now();
            $autorizacion->save();

            $this->ejecutarDestino($autorizacion);

            $this->auditoria->registrar(
                accion: 'autorizacion.aprobada',
                entidad: 'autorizaciones',
                entidadId: $autorizacion->id,
                datosDespues: [
                    'tipo' => $autorizacion->tipo,
                    'entidad' => $autorizacion->entidad,
                    'entidad_id' => $autorizacion->entidad_id,
                ],
            );

            event(new AutorizacionResuelta($autorizacion));

            return $autorizacion;
        });
    }

    public function rechazar(Autorizacion $autorizacion, array $datos = []): Autorizacion
    {
        return DB::transaction(function () use ($autorizacion, $datos) {
            $autorizacion = $this->bloquearPendiente($autorizacion);

            $autorizacion->estado = EstadoAutorizacion::Rechazada->value;
            $autorizacion->id_usuario_autoriza = Auth::id();
            $autorizacion->resuelta_at = now();
            $autorizacion->save();

            $this->auditoria->registrar(
                accion: 'autorizacion.rechazada',
                entidad: 'autorizaciones',
                entidadId: $autorizacion->id,
                datosDespues: [
                    'tipo' => $autorizacion->tipo,
                    'motivo_rechazo' => $datos['motivo'] ?? null,
                ],
            );

            event(new AutorizacionResuelta($autorizacion));

            return $autorizacion;
        });
    }

    /** Re-lee la autorización con bloqueo y exige que siga `pendiente` (idempotencia). */
    private function bloquearPendiente(Autorizacion $autorizacion): Autorizacion
    {
        $autorizacion = Autorizacion::whereKey($autorizacion->id)->lockForUpdate()->firstOrFail();

        if ($autorizacion->estadoAutorizacion()->esResuelta()) {
            throw new AutorizacionYaResueltaException;
        }

        return $autorizacion;
    }

    /** Ejecuta el servicio destino según el tipo, enlazando id_autorizacion. */
    private function ejecutarDestino(Autorizacion $autorizacion): void
    {
        $tipo = TipoAutorizacion::from($autorizacion->tipo);
        $payload = $autorizacion->datos ?? [];
        $motivo = $autorizacion->motivo;

        match ($tipo) {
            TipoAutorizacion::CancelarItem => $this->ejecutarCancelarItem($autorizacion, $payload, $motivo),
            TipoAutorizacion::AnularOrden => $this->ejecutarAnularOrden($payload, $motivo),
            TipoAutorizacion::EntradaStock, TipoAutorizacion::AjusteStock => $this->ejecutarInventario($autorizacion, $payload, $motivo),
        };
    }

    private function ejecutarCancelarItem(Autorizacion $autorizacion, array $payload, ?string $motivo): void
    {
        $orden = Orden::whereKey($payload['id_orden'])->firstOrFail();
        $item = $orden->detalles()->whereKey($payload['id_item'])->firstOrFail();

        $this->cancelarItem->cancelar($orden, $item, [
            'motivo' => $motivo,
            'id_autorizacion' => $autorizacion->id,
        ]);
    }

    private function ejecutarAnularOrden(array $payload, ?string $motivo): void
    {
        $orden = Orden::whereKey($payload['id_orden'])->firstOrFail();

        $this->anularOrden->anular($orden, ['motivo' => $motivo]);
    }

    private function ejecutarInventario(Autorizacion $autorizacion, array $payload, ?string $motivo): void
    {
        $this->registrarMovimiento->registrar([
            'id_insumo' => $payload['id_insumo'],
            'tipo' => $payload['tipo'],
            'cantidad' => $payload['cantidad'],
            'costo_unitario' => $payload['costo_unitario'] ?? null,
            'motivo' => $motivo,
            'id_autorizacion' => $autorizacion->id,
        ]);
    }
}
