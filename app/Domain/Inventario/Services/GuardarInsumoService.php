<?php

namespace App\Domain\Inventario\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\Insumo;
use Illuminate\Support\Facades\DB;

/**
 * M08 · Escritura del catálogo de insumos (alta, edición y activación).
 * Atómica y auditada (§15). NO edita stock_actual: la existencia solo cambia por
 * movimientos del ledger (D2). El alta nace con stock_actual = 0 (default de BD),
 * salvo que el payload traiga `stock_inicial` (ver `crear`).
 */
class GuardarInsumoService
{
    public function __construct(
        private readonly RegistrarAuditoriaService $auditoria,
        private readonly RegistrarMovimientoService $movimientos,
    ) {}

    private const CAMPOS = [
        'id_unidad_medida', 'id_proveedor', 'nombre', 'tipo',
        'stock_minimo', 'costo_unitario', 'activo',
    ];

    public function crear(array $datos): Insumo
    {
        return DB::transaction(function () use ($datos) {
            $stockInicial = $datos['stock_inicial'] ?? null;

            // stock_actual no se toma del payload: nace en 0 y solo cambia por movimientos.
            $insumo = Insumo::create($this->soloEditables($datos) + ['stock_actual' => 0]);

            $this->auditoria->registrar(
                accion: 'insumo.creado',
                entidad: 'insumos',
                entidadId: $insumo->id,
                datosDespues: $insumo->only(self::CAMPOS),
            );

            // `stock_inicial` no es un campo del insumo: es el atajo de UI para no
            // obligar a un segundo viaje a "Registrar movimiento" nada más crearlo.
            // Se traduce a una entrada real del ledger, en la misma transacción, así
            // que el D2 (la existencia solo cambia por movimientos) se sigue cumpliendo.
            if ($stockInicial !== null) {
                $this->movimientos->registrar([
                    'id_insumo' => $insumo->id,
                    'tipo' => 'entrada',
                    'cantidad' => $stockInicial,
                    'costo_unitario' => $insumo->costo_unitario,
                    'motivo' => 'Existencia inicial al crear el insumo',
                ]);
                $insumo->refresh();
            }

            return $insumo;
        });
    }

    public function actualizar(Insumo $insumo, array $datos): Insumo
    {
        $datos = $this->soloEditables($datos);

        return DB::transaction(function () use ($insumo, $datos) {
            $antes = $insumo->only(array_keys($datos));
            $insumo->fill($datos)->save();

            $this->auditoria->registrar(
                accion: 'insumo.actualizado',
                entidad: 'insumos',
                entidadId: $insumo->id,
                datosAntes: $antes,
                datosDespues: $insumo->only(array_keys($datos)),
            );

            return $insumo;
        });
    }

    public function cambiarEstado(Insumo $insumo, bool $activo): Insumo
    {
        return DB::transaction(function () use ($insumo, $activo) {
            $antes = $insumo->activo;
            $insumo->activo = $activo;
            $insumo->save();

            $this->auditoria->registrar(
                accion: $activo ? 'insumo.activado' : 'insumo.desactivado',
                entidad: 'insumos',
                entidadId: $insumo->id,
                datosAntes: ['activo' => $antes],
                datosDespues: ['activo' => $activo],
            );

            return $insumo;
        });
    }

    /** Descarta stock_actual y cualquier campo no gestionable por el CRUD. */
    private function soloEditables(array $datos): array
    {
        return array_intersect_key($datos, array_flip(self::CAMPOS));
    }
}
