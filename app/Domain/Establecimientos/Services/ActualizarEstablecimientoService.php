<?php

namespace App\Domain\Establecimientos\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\Establecimiento;
use Illuminate\Support\Facades\DB;

/**
 * Edición de los datos base de un establecimiento (M02). No toca la configuración
 * de ticket/impuesto (eso es M03, fuera del alcance del Sprint 1).
 */
class ActualizarEstablecimientoService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function actualizar(Establecimiento $establecimiento, array $datos): Establecimiento
    {
        return DB::transaction(function () use ($establecimiento, $datos) {
            $antes = $establecimiento->only(array_keys($datos));

            $establecimiento->fill($datos)->save();

            $this->auditoria->registrar(
                accion: 'establecimiento.actualizado',
                entidad: 'establecimientos',
                entidadId: $establecimiento->id,
                datosAntes: $antes,
                datosDespues: $establecimiento->only(array_keys($datos)),
            );

            return $establecimiento;
        });
    }
}
