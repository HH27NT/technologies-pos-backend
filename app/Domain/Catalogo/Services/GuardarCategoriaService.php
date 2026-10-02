<?php

namespace App\Domain\Catalogo\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\CategoriaProducto;
use Illuminate\Support\Facades\DB;

/**
 * M05 · Escritura del catálogo de categorías (alta, edición y activación).
 * Atómica y auditada dentro de la misma transacción (§15). El id_establecimiento
 * lo autollena BelongsToTenant desde el TenantContext.
 */
class GuardarCategoriaService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function crear(array $datos): CategoriaProducto
    {
        return DB::transaction(function () use ($datos) {
            $categoria = CategoriaProducto::create($datos);

            $this->auditoria->registrar(
                accion: 'categoria.creada',
                entidad: 'categorias_producto',
                entidadId: $categoria->id,
                datosDespues: $categoria->only(['nombre', 'orden_display', 'activo']),
            );

            return $categoria;
        });
    }

    public function actualizar(CategoriaProducto $categoria, array $datos): CategoriaProducto
    {
        return DB::transaction(function () use ($categoria, $datos) {
            $antes = $categoria->only(array_keys($datos));
            $categoria->fill($datos)->save();

            $this->auditoria->registrar(
                accion: 'categoria.actualizada',
                entidad: 'categorias_producto',
                entidadId: $categoria->id,
                datosAntes: $antes,
                datosDespues: $categoria->only(array_keys($datos)),
            );

            return $categoria;
        });
    }

    public function cambiarEstado(CategoriaProducto $categoria, bool $activo): CategoriaProducto
    {
        return DB::transaction(function () use ($categoria, $activo) {
            $antes = $categoria->activo;
            $categoria->activo = $activo;
            $categoria->save();

            $this->auditoria->registrar(
                accion: $activo ? 'categoria.activada' : 'categoria.desactivada',
                entidad: 'categorias_producto',
                entidadId: $categoria->id,
                datosAntes: ['activo' => $antes],
                datosDespues: ['activo' => $activo],
            );

            return $categoria;
        });
    }
}
