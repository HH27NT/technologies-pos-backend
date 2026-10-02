<?php

namespace App\Domain\Establecimientos\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\ConfiguracionEstablecimiento;
use App\Support\Exceptions\ConfiguracionInvalidaException;
use Illuminate\Support\Facades\DB;

/**
 * M03 · Edición de la configuración 1:1 del establecimiento (datos de ticket,
 * impresión automática, stock mínimo global e impuesto P11). Atómica y auditada
 * dentro de la misma transacción (§15).
 */
class ActualizarConfiguracionService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function actualizar(ConfiguracionEstablecimiento $configuracion, array $datos): ConfiguracionEstablecimiento
    {
        $this->validarImpuesto($configuracion, $datos);

        return DB::transaction(function () use ($configuracion, $datos) {
            $antes = $configuracion->only(array_keys($datos));

            $configuracion->fill($datos)->save();

            $this->auditoria->registrar(
                accion: 'configuracion.actualizada',
                entidad: 'configuracion_establecimiento',
                entidadId: $configuracion->id,
                datosAntes: $antes,
                datosDespues: $configuracion->only(array_keys($datos)),
            );

            return $configuracion;
        });
    }

    /**
     * Regla de estado (P11): si el impuesto queda activo, la tasa resultante debe
     * ser mayor que cero. Se evalúa sobre el estado final (datos entrantes sobre
     * los valores actuales) para permitir actualizaciones parciales.
     */
    private function validarImpuesto(ConfiguracionEstablecimiento $configuracion, array $datos): void
    {
        $aplica = array_key_exists('aplica_impuesto', $datos)
            ? (bool) $datos['aplica_impuesto']
            : (bool) $configuracion->aplica_impuesto;

        $tasa = array_key_exists('tasa_impuesto', $datos)
            ? $datos['tasa_impuesto']
            : $configuracion->tasa_impuesto;

        if ($aplica && (is_null($tasa) || (float) $tasa <= 0)) {
            throw new ConfiguracionInvalidaException;
        }
    }
}
