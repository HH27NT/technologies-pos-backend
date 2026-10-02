<?php

namespace App\Domain\Autorizaciones\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\AutorizacionPin;
use App\Models\Usuario;
use App\Support\Exceptions\PinEnUsoException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * M14.1 · Gestión self-service del PIN de autorización (`/mi-pin`).
 *
 * El propio autorizador fija su PIN; nadie más (ni el super admin) lo hace por él, para que
 * el secreto de autorización no tenga copia conocida por terceros. La auditoría registra que
 * hubo un cambio, nunca el valor.
 */
class GestionarPinService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    /** PIN vigente del usuario en el establecimiento activo (null si no lo tiene configurado). */
    public function actual(Usuario $usuario): ?AutorizacionPin
    {
        return AutorizacionPin::where('id_usuario', $usuario->id)->first();
    }

    /**
     * Establece o cambia el PIN (upsert por membresía).
     *
     * @throws PinEnUsoException si otro usuario del tenant ya usa ese PIN.
     */
    public function fijar(Usuario $usuario, string $pin): AutorizacionPin
    {
        $lookup = AutorizacionPin::lookup($pin);

        // El TenantScope acota la búsqueda al establecimiento activo: la unicidad que se
        // exige es dentro del tenant, no global.
        $enUso = AutorizacionPin::where('pin_lookup', $lookup)
            ->where('id_usuario', '!=', $usuario->id)
            ->exists();

        if ($enUso) {
            throw new PinEnUsoException;
        }

        try {
            return DB::transaction(function () use ($usuario, $pin, $lookup) {
                $registro = AutorizacionPin::updateOrCreate(
                    ['id_usuario' => $usuario->id],
                    ['pin_hash' => $pin, 'pin_lookup' => $lookup, 'actualizado_at' => now()],
                );

                $this->auditoria->registrar(
                    accion: 'autorizacion.pin_fijado',
                    entidad: 'autorizacion_pins',
                    entidadId: $registro->id,
                    datosDespues: ['id_usuario' => $usuario->id],  // Jamás el PIN (§ Seguridad).
                );

                return $registro;
            });
        } catch (QueryException $e) {
            // Carrera contra el índice único (dos admins fijando el mismo PIN a la vez).
            throw new PinEnUsoException;
        }
    }

    /** Elimina el PIN: el usuario queda "sin configurar" y deja de poder autorizar overrides. */
    public function eliminar(Usuario $usuario): void
    {
        $registro = $this->actual($usuario);

        if ($registro === null) {
            return;
        }

        DB::transaction(function () use ($registro, $usuario) {
            $registro->delete();

            $this->auditoria->registrar(
                accion: 'autorizacion.pin_eliminado',
                entidad: 'autorizacion_pins',
                entidadId: $registro->id,
                datosAntes: ['id_usuario' => $usuario->id],
            );
        });
    }
}
