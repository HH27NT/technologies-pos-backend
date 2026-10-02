<?php

namespace App\Domain\Inventario\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\RecetaProducto;
use App\Support\Exceptions\RecetaDuplicadaException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * M07 · Gestión de las líneas de receta (BOM, puente producto↔insumo).
 * El par producto+insumo es único; la cantidad se corrige editando la línea.
 * Atómica y auditada dentro de la misma transacción (§15).
 */
class GestionarRecetaService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    private const CAMPOS = ['id_producto', 'id_insumo', 'cantidad'];

    public function crear(array $datos): RecetaProducto
    {
        $this->garantizarParUnico((int) $datos['id_producto'], (int) $datos['id_insumo']);

        return DB::transaction(function () use ($datos) {
            $receta = RecetaProducto::create($datos);

            $this->auditoria->registrar(
                accion: 'receta.creada',
                entidad: 'recetas_producto',
                entidadId: $receta->id,
                datosDespues: $receta->only(self::CAMPOS),
            );

            return $receta;
        });
    }

    public function actualizar(RecetaProducto $receta, array $datos): RecetaProducto
    {
        $idInsumo = (int) ($datos['id_insumo'] ?? $receta->id_insumo);
        $this->garantizarParUnico((int) $receta->id_producto, $idInsumo, $receta->id);

        return DB::transaction(function () use ($receta, $datos) {
            $antes = $receta->only(array_keys($datos));
            $receta->fill($datos)->save();

            $this->auditoria->registrar(
                accion: 'receta.actualizada',
                entidad: 'recetas_producto',
                entidadId: $receta->id,
                datosAntes: $antes,
                datosDespues: $receta->only(array_keys($datos)),
            );

            return $receta;
        });
    }

    public function eliminar(RecetaProducto $receta): void
    {
        DB::transaction(function () use ($receta) {
            $this->auditoria->registrar(
                accion: 'receta.eliminada',
                entidad: 'recetas_producto',
                entidadId: $receta->id,
                datosAntes: $receta->only(self::CAMPOS),
            );

            $receta->delete();
        });
    }

    /**
     * Reemplaza la receta COMPLETA de un producto por los renglones dados, en una
     * transacción. Devuelve la receta resultante.
     *
     * No borra y vuelve a insertar: **diferencia**. Borrar todo dejaría el ledger de
     * auditoría lleno de bajas y altas idénticas cada vez que alguien corrige una
     * cantidad, y ahí es justo donde se busca quién cambió qué. Se reutilizan los
     * métodos ya auditados: se elimina lo que salió, se actualiza lo que cambió de
     * cantidad, se crea lo que entró, y lo idéntico no toca la base.
     *
     * El orden importa: primero las bajas, porque el par producto+insumo es único y
     * un intercambio de insumos chocaría contra el índice si se creara antes.
     *
     * @param  list<array{id_insumo: int, cantidad: numeric}>  $renglones
     * @return Collection<int, RecetaProducto>
     */
    public function reemplazar(int $idProducto, array $renglones): Collection
    {
        return DB::transaction(function () use ($idProducto, $renglones) {
            $actuales = RecetaProducto::where('id_producto', $idProducto)->get()->keyBy('id_insumo');
            $nuevos = collect($renglones)->keyBy(fn (array $r) => (int) $r['id_insumo']);

            foreach ($actuales as $idInsumo => $receta) {
                if (! $nuevos->has($idInsumo)) {
                    $this->eliminar($receta);
                }
            }

            foreach ($nuevos as $idInsumo => $renglon) {
                $existente = $actuales->get($idInsumo);

                if ($existente === null) {
                    $this->crear([
                        'id_producto' => $idProducto,
                        'id_insumo' => $idInsumo,
                        'cantidad' => $renglon['cantidad'],
                    ]);

                    continue;
                }

                // La cantidad se compara ya normalizada por el cast decimal:3, para que
                // "60" y "60.000" no cuenten como un cambio y ensucien la auditoría.
                if ((string) $existente->cantidad !== (string) round((float) $renglon['cantidad'], 3)) {
                    $this->actualizar($existente, ['cantidad' => $renglon['cantidad']]);
                }
            }

            return RecetaProducto::where('id_producto', $idProducto)
                ->with(['producto', 'insumo'])
                ->orderBy('id')
                ->get();
        });
    }

    private function garantizarParUnico(int $idProducto, int $idInsumo, ?int $ignorarId = null): void
    {
        $existe = RecetaProducto::where('id_producto', $idProducto)
            ->where('id_insumo', $idInsumo)
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->exists();

        if ($existe) {
            throw new RecetaDuplicadaException;
        }
    }
}
