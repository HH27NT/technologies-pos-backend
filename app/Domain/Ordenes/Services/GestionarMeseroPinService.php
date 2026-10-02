<?php

namespace App\Domain\Ordenes\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Models\MeseroPin;
use App\Models\Usuario;
use App\Support\Exceptions\PinEnUsoException;
use Illuminate\Support\Facades\DB;

/**
 * Alta, cambio y baja del PIN de mesero.
 *
 * ⚠️ **Aquí el PIN NO es self-service, a diferencia de M14.1.** El PIN de autorización lo fija
 * solo su dueño, porque es el secreto que aprueba dinero y no debe tener copia conocida. El de
 * mesero es lo contrario: en una barra con tablet compartida, el mesero muchas veces **no tiene
 * contraseña ni dispositivo propio** con el que entrar a fijárselo. Exigir self-service dejaría
 * el modo entero inusable, así que lo fija quien administra el personal (`usuarios.gestionar`).
 *
 * El riesgo que eso abre está acotado a propósito: quien conozca el PIN puede **atribuirse una
 * venta**, no autorizar nada. Y quien lo fija (el admin) ya podía reasignar órdenes con
 * `ordenes.reasignar`, así que no gana poder que no tuviera.
 *
 * La auditoría registra que hubo un cambio y sobre quién; jamás el valor.
 */
class GestionarMeseroPinService
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    /** PIN vigente del mesero (null si no tiene). El valor nunca se puede recuperar, solo reemplazar. */
    public function actual(Usuario $mesero): ?MeseroPin
    {
        return MeseroPin::where('id_usuario', $mesero->id)->first();
    }

    /**
     * Fija o cambia el PIN del mesero.
     *
     * @throws PinEnUsoException si otro miembro del personal ya usa ese PIN en este
     *                           establecimiento. La unicidad no es capricho: el mesero teclea
     *                           SOLO 6 dígitos, así que dos PIN iguales harían imposible saber
     *                           a quién atribuir la venta.
     */
    public function fijar(Usuario $mesero, string $pin): MeseroPin
    {
        $lookup = MeseroPin::lookup($pin);

        // El TenantScope acota al establecimiento activo: la unicidad se exige dentro del
        // tenant, no globalmente (dos bares pueden usar el mismo PIN sin saberlo).
        $enUso = MeseroPin::where('pin_lookup', $lookup)
            ->where('id_usuario', '!=', $mesero->id)
            ->exists();

        if ($enUso) {
            throw new PinEnUsoException;
        }

        return DB::transaction(function () use ($mesero, $pin, $lookup) {
            $registro = MeseroPin::updateOrCreate(
                ['id_usuario' => $mesero->id],
                ['pin_hash' => $pin, 'pin_lookup' => $lookup, 'actualizado_at' => now()],
            );

            $this->auditoria->registrar(
                accion: 'mesero.pin_fijado',
                entidad: 'mesero_pins',
                entidadId: $registro->id,
                datosDespues: ['id_usuario' => $mesero->id],  // Jamás el PIN.
            );

            return $registro;
        });
    }

    /** Retira el PIN (el mesero deja de poder firmar ventas en la terminal compartida). */
    public function quitar(Usuario $mesero): void
    {
        $registro = $this->actual($mesero);

        if (! $registro) {
            return;
        }

        DB::transaction(function () use ($registro, $mesero) {
            $id = $registro->id;
            $registro->delete();

            $this->auditoria->registrar(
                accion: 'mesero.pin_retirado',
                entidad: 'mesero_pins',
                entidadId: $id,
                datosAntes: ['id_usuario' => $mesero->id],
            );
        });
    }
}
