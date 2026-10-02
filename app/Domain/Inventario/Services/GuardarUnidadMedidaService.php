<?php

namespace App\Domain\Inventario\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\UnidadMedida;
use App\Support\Exceptions\UnidadEnUsoException;
use App\Support\Exceptions\UnidadGlobalNoEditableException;
use Illuminate\Support\Facades\DB;

/**
 * M08 · Escritura de unidades de medida PROPIAS del tenant (P4). Las globales
 * (id_establecimiento NULL) son de solo lectura: editar o eliminar una global
 * lanza UnidadGlobalNoEditableException. Atómica y auditada (§15).
 */
class GuardarUnidadMedidaService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    private const CAMPOS = ['nombre', 'abreviacion'];

    public function crear(array $datos): UnidadMedida
    {
        return DB::transaction(function () use ($datos) {
            // IncluyeGlobales autollena id_establecimiento con el tenant actual ⇒ unidad propia.
            $unidad = UnidadMedida::create($datos);

            $this->auditoria->registrar(
                accion: 'unidad_medida.creada',
                entidad: 'unidades_medida',
                entidadId: $unidad->id,
                datosDespues: $unidad->only(self::CAMPOS),
            );

            return $unidad;
        });
    }

    public function actualizar(UnidadMedida $unidad, array $datos): UnidadMedida
    {
        $this->garantizarPropia($unidad);

        return DB::transaction(function () use ($unidad, $datos) {
            $antes = $unidad->only(array_keys($datos));
            $unidad->fill($datos)->save();

            $this->auditoria->registrar(
                accion: 'unidad_medida.actualizada',
                entidad: 'unidades_medida',
                entidadId: $unidad->id,
                datosAntes: $antes,
                datosDespues: $unidad->only(array_keys($datos)),
            );

            return $unidad;
        });
    }

    public function eliminar(UnidadMedida $unidad): void
    {
        $this->garantizarPropia($unidad);

        if ($unidad->insumos()->exists()) {
            throw new UnidadEnUsoException;
        }

        DB::transaction(function () use ($unidad) {
            $this->auditoria->registrar(
                accion: 'unidad_medida.eliminada',
                entidad: 'unidades_medida',
                entidadId: $unidad->id,
                datosAntes: $unidad->only(self::CAMPOS),
            );

            $unidad->delete();
        });
    }

    private function garantizarPropia(UnidadMedida $unidad): void
    {
        if ($unidad->esGlobal()) {
            throw new UnidadGlobalNoEditableException;
        }
    }
}
