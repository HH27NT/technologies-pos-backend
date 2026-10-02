<?php

namespace App\Domain\Caja\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Caja\Events\CajaAbierta;
use App\Models\Establecimiento;
use App\Models\SesionCaja;
use App\Support\Exceptions\CajaYaAbiertaException;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * M10 · Apertura de la sesión de caja del establecimiento. Atómica y auditada (§15).
 *
 * Regla "una sola caja abierta": se serializa con un bloqueo pesimista sobre el
 * establecimiento (lockForUpdate) y se verifica que no exista una sesión `abierta`.
 * En PostgreSQL el índice único parcial es la última salvaguarda; en SQLite (sin
 * índice parcial) la garantía recae en este bloqueo + verificación.
 */
class AbrirCajaService
{
    public function __construct(
        private readonly RegistrarAuditoriaService $auditoria,
        private readonly TenantContext $tenant,
    ) {}

    public function abrir(array $datos): SesionCaja
    {
        return DB::transaction(function () use ($datos) {
            $idEstablecimiento = $this->tenant->id();

            // Serializa las aperturas concurrentes del mismo establecimiento.
            Establecimiento::whereKey($idEstablecimiento)->lockForUpdate()->firstOrFail();

            if (SesionCaja::where('estado', 'abierta')->exists()) {
                throw new CajaYaAbiertaException;
            }

            $sesion = SesionCaja::create([
                'id_usuario_apertura' => Auth::id(),
                'monto_inicial' => $datos['monto_inicial'],
                'estado' => 'abierta',
                'abierta_at' => now(),
            ]);

            $this->auditoria->registrar(
                accion: 'caja.abierta',
                entidad: 'sesiones_caja',
                entidadId: $sesion->id,
                datosDespues: [
                    'monto_inicial' => (float) $sesion->monto_inicial,
                    'id_usuario_apertura' => $sesion->id_usuario_apertura,
                ],
            );

            event(new CajaAbierta($sesion));

            return $sesion;
        });
    }
}
