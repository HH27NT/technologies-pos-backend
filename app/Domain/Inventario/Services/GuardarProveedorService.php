<?php

namespace App\Domain\Inventario\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\Proveedor;
use Illuminate\Support\Facades\DB;

/**
 * M08 · Escritura del catálogo de proveedores (alta, edición y activación).
 * Atómica y auditada dentro de la misma transacción (§15). El id_establecimiento
 * lo autollena BelongsToTenant desde el TenantContext.
 */
class GuardarProveedorService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    private const CAMPOS = ['nombre', 'telefono', 'email', 'activo'];

    public function crear(array $datos): Proveedor
    {
        return DB::transaction(function () use ($datos) {
            $proveedor = Proveedor::create($datos);

            $this->auditoria->registrar(
                accion: 'proveedor.creado',
                entidad: 'proveedores',
                entidadId: $proveedor->id,
                datosDespues: $proveedor->only(self::CAMPOS),
            );

            return $proveedor;
        });
    }

    public function actualizar(Proveedor $proveedor, array $datos): Proveedor
    {
        return DB::transaction(function () use ($proveedor, $datos) {
            $antes = $proveedor->only(array_keys($datos));
            $proveedor->fill($datos)->save();

            $this->auditoria->registrar(
                accion: 'proveedor.actualizado',
                entidad: 'proveedores',
                entidadId: $proveedor->id,
                datosAntes: $antes,
                datosDespues: $proveedor->only(array_keys($datos)),
            );

            return $proveedor;
        });
    }

    public function cambiarEstado(Proveedor $proveedor, bool $activo): Proveedor
    {
        return DB::transaction(function () use ($proveedor, $activo) {
            $antes = $proveedor->activo;
            $proveedor->activo = $activo;
            $proveedor->save();

            $this->auditoria->registrar(
                accion: $activo ? 'proveedor.activado' : 'proveedor.desactivado',
                entidad: 'proveedores',
                entidadId: $proveedor->id,
                datosAntes: ['activo' => $antes],
                datosDespues: ['activo' => $activo],
            );

            return $proveedor;
        });
    }
}
