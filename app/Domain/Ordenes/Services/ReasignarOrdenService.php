<?php

namespace App\Domain\Ordenes\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\Orden;
use App\Support\Exceptions\OrdenNoModificableException;
use Illuminate\Support\Facades\DB;

/**
 * M11 · Reasignar el mesero (id_usuario) de una orden ABIERTA — traspaso de cuenta/mesa
 * cuando un mesero deja el turno. Acción de gestión (admin/gerente), atómica y auditada
 * (§15); no toca la caja ni los importes congelados. Solo cambia la atribución.
 */
class ReasignarOrdenService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function reasignar(Orden $orden, int $idUsuario): Orden
    {
        return DB::transaction(function () use ($orden, $idUsuario) {
            $orden = Orden::whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if (! $orden->estadoOrden()->esModificable()) {
                throw new OrdenNoModificableException;
            }

            $antes = $orden->id_usuario;

            // Sin cambio real: no auditar ruido.
            if ($antes === $idUsuario) {
                return $orden;
            }

            $orden->id_usuario = $idUsuario;
            $orden->save();

            $this->auditoria->registrar(
                accion: 'orden.reasignada',
                entidad: 'ordenes',
                entidadId: $orden->id,
                datosAntes: ['id_usuario' => $antes],
                datosDespues: ['id_usuario' => $idUsuario],
            );

            return $orden;
        });
    }
}
