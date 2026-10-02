<?php

namespace App\Domain\Catalogo\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\Mesa;
use Illuminate\Support\Facades\DB;

/**
 * M09 · Escritura del catálogo de mesas (alta, edición y activación).
 * Atómica y auditada (§15). El estado ocupada/libre NO se modela aquí: se deriva
 * de la orden abierta (fuente de verdad: M11).
 */
class GuardarMesaService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    private const CAMPOS = ['numero', 'nombre', 'zona', 'capacidad', 'activa'];

    public function crear(array $datos): Mesa
    {
        return DB::transaction(function () use ($datos) {
            // Autoasigna el número si no se mandó: siguiente consecutivo del
            // establecimiento (el índice único sigue siendo la garantía real ante
            // una carrera entre dos altas simultáneas).
            $datos['numero'] ??= (Mesa::max('numero') ?? 0) + 1;

            $mesa = Mesa::create($datos);

            $this->auditoria->registrar(
                accion: 'mesa.creada',
                entidad: 'mesas',
                entidadId: $mesa->id,
                datosDespues: $mesa->only(self::CAMPOS),
            );

            return $mesa;
        });
    }

    public function actualizar(Mesa $mesa, array $datos): Mesa
    {
        return DB::transaction(function () use ($mesa, $datos) {
            $antes = $mesa->only(array_keys($datos));
            $mesa->fill($datos)->save();

            $this->auditoria->registrar(
                accion: 'mesa.actualizada',
                entidad: 'mesas',
                entidadId: $mesa->id,
                datosAntes: $antes,
                datosDespues: $mesa->only(array_keys($datos)),
            );

            return $mesa;
        });
    }

    public function cambiarEstado(Mesa $mesa, bool $activa): Mesa
    {
        return DB::transaction(function () use ($mesa, $activa) {
            $antes = $mesa->activa;
            $mesa->activa = $activa;
            $mesa->save();

            $this->auditoria->registrar(
                accion: $activa ? 'mesa.activada' : 'mesa.desactivada',
                entidad: 'mesas',
                entidadId: $mesa->id,
                datosAntes: ['activa' => $antes],
                datosDespues: ['activa' => $activa],
            );

            return $mesa;
        });
    }
}
