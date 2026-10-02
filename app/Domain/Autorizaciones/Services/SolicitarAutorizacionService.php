<?php

namespace App\Domain\Autorizaciones\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Autorizaciones\Events\AutorizacionSolicitada;
use App\Domain\Autorizaciones\TipoAutorizacion;
use App\Domain\Ordenes\EstadoItem;
use App\Models\Autorizacion;
use App\Models\Insumo;
use App\Models\Orden;
use App\Support\Exceptions\AutorizacionInvalidaException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * M14 · Alta de una solicitud de operación sensible (operador → bandeja del admin).
 * Atómico y auditado (§15).
 *
 * Resuelve el sujeto DENTRO del tenant (TenantScope → 404 si es de otro establecimiento)
 * y valida que admita la operación en su estado actual; arma la autorización `pendiente`
 * con entidad/entidad_id (referencia polimórfica), `motivo` (justificación, D3) y `datos`
 * (payload necesario al ejecutar al aprobar). NO ejecuta nada: la operación se materializa
 * cuando el admin aprueba (ResolverAutorizacionService).
 */
class SolicitarAutorizacionService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function solicitar(array $datos): Autorizacion
    {
        return DB::transaction(function () use ($datos) {
            $tipo = TipoAutorizacion::from($datos['tipo']);

            [$entidadId, $payload] = $this->resolverSujeto($tipo, $datos);

            $autorizacion = Autorizacion::create([
                'id_usuario_solicita' => Auth::id(),
                'tipo' => $tipo->value,
                'entidad' => $tipo->entidad(),
                'entidad_id' => $entidadId,
                'estado' => 'pendiente',
                'motivo' => $datos['motivo'],
                'datos' => $payload,
            ]);

            $this->auditoria->registrar(
                accion: 'autorizacion.solicitada',
                entidad: 'autorizaciones',
                entidadId: $autorizacion->id,
                datosDespues: [
                    'tipo' => $tipo->value,
                    'entidad' => $tipo->entidad(),
                    'entidad_id' => $entidadId,
                    'motivo' => $datos['motivo'],
                ],
            );

            event(new AutorizacionSolicitada($autorizacion));

            return $autorizacion;
        });
    }

    /**
     * Valida el sujeto (existencia por tenant + estado solicitable) y devuelve
     * [entidad_id, payload] para persistir en la autorización.
     *
     * @return array{0:int,1:array}
     */
    private function resolverSujeto(TipoAutorizacion $tipo, array $datos): array
    {
        return match ($tipo) {
            TipoAutorizacion::CancelarItem => $this->sujetoCancelarItem($datos),
            TipoAutorizacion::AnularOrden => $this->sujetoAnularOrden($datos),
            TipoAutorizacion::EntradaStock, TipoAutorizacion::AjusteStock => $this->sujetoInventario($tipo, $datos),
        };
    }

    private function sujetoCancelarItem(array $datos): array
    {
        $orden = Orden::whereKey($datos['id_orden'])->firstOrFail();

        if (! $orden->estadoOrden()->esModificable()) {
            throw new AutorizacionInvalidaException('La orden no admite cancelación de ítems en su estado actual.');
        }

        $item = $orden->detalles()->whereKey($datos['id_item'])->firstOrFail();

        if ($item->estado_item === EstadoItem::Cancelado->value) {
            throw new AutorizacionInvalidaException('El renglón ya está cancelado.');
        }

        return [$item->id, ['id_orden' => $orden->id, 'id_item' => $item->id]];
    }

    private function sujetoAnularOrden(array $datos): array
    {
        $orden = Orden::whereKey($datos['id_orden'])->firstOrFail();

        if (! $orden->estadoOrden()->esModificable()) {
            throw new AutorizacionInvalidaException('La orden no admite anulación en su estado actual.');
        }

        return [$orden->id, ['id_orden' => $orden->id]];
    }

    private function sujetoInventario(TipoAutorizacion $tipo, array $datos): array
    {
        $insumo = Insumo::whereKey($datos['id_insumo'])->firstOrFail();

        return [$insumo->id, [
            'id_insumo' => $insumo->id,
            'tipo' => $tipo->tipoMovimiento(),
            'cantidad' => (float) $datos['cantidad'],
            'costo_unitario' => isset($datos['costo_unitario']) ? (float) $datos['costo_unitario'] : null,
        ]];
    }
}
