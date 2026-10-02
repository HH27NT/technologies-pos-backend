<?php

namespace App\Domain\Catalogo\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\Impresora;
use Illuminate\Support\Facades\DB;

/**
 * M13 · Escritura del catálogo de impresoras (alta, edición y activación).
 * Atómica y auditada (§15). La generación de comanda/ticket se conecta en la capa 5.
 */
class GuardarImpresoraService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    private const CAMPOS = ['nombre', 'tipo', 'conexion', 'activa'];

    public function crear(array $datos): Impresora
    {
        return DB::transaction(function () use ($datos) {
            $impresora = Impresora::create($datos);

            $this->auditoria->registrar(
                accion: 'impresora.creada',
                entidad: 'impresoras',
                entidadId: $impresora->id,
                datosDespues: $impresora->only(self::CAMPOS),
            );

            return $impresora;
        });
    }

    public function actualizar(Impresora $impresora, array $datos): Impresora
    {
        return DB::transaction(function () use ($impresora, $datos) {
            $antes = $impresora->only(array_keys($datos));
            $impresora->fill($datos)->save();

            $this->auditoria->registrar(
                accion: 'impresora.actualizada',
                entidad: 'impresoras',
                entidadId: $impresora->id,
                datosAntes: $antes,
                datosDespues: $impresora->only(array_keys($datos)),
            );

            return $impresora;
        });
    }

    public function cambiarEstado(Impresora $impresora, bool $activa): Impresora
    {
        return DB::transaction(function () use ($impresora, $activa) {
            $antes = $impresora->activa;
            $impresora->activa = $activa;
            $impresora->save();

            $this->auditoria->registrar(
                accion: $activa ? 'impresora.activada' : 'impresora.desactivada',
                entidad: 'impresoras',
                entidadId: $impresora->id,
                datosAntes: ['activa' => $antes],
                datosDespues: ['activa' => $activa],
            );

            return $impresora;
        });
    }
}
