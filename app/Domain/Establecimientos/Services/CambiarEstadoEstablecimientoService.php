<?php

namespace App\Domain\Establecimientos\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\Establecimiento;
use Illuminate\Support\Facades\DB;

/**
 * Activa/desactiva un establecimiento (M02 · P19). Al desactivar, revoca los tokens
 * de todos sus usuarios (compensación de P18: tokens sin caducidad). No se elimina.
 */
class CambiarEstadoEstablecimientoService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function cambiar(Establecimiento $establecimiento, bool $activo): Establecimiento
    {
        return DB::transaction(function () use ($establecimiento, $activo) {
            $antes = $establecimiento->activo;
            $establecimiento->activo = $activo;
            $establecimiento->save();

            if (! $activo) {
                // Revoca el acceso de los usuarios del tenant desactivado.
                foreach ($establecimiento->usuarios()->withTrashed()->get() as $usuario) {
                    $usuario->tokens()->delete();
                }
            }

            $this->auditoria->registrar(
                accion: $activo ? 'establecimiento.activado' : 'establecimiento.desactivado',
                entidad: 'establecimientos',
                entidadId: $establecimiento->id,
                datosAntes: ['activo' => $antes],
                datosDespues: ['activo' => $activo],
            );

            return $establecimiento;
        });
    }
}
