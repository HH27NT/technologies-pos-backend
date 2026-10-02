<?php

namespace App\Domain\Ordenes\Services;

use App\Models\MeseroPin;
use App\Models\Usuario;
use App\Support\Exceptions\DemasiadosIntentosException;
use App\Support\Exceptions\PinMeseroInvalidoException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Resuelve a la persona detrás de 6 dígitos, en la terminal compartida.
 *
 * Lo que este servicio hace **no es autenticar**: no emite token, no cambia la sesión y no
 * concede ningún permiso. La tablet sigue operando con la cuenta de terminal; lo único que
 * ocurre es que la venta queda firmada por quien tecleó. Si algún día alguien intenta usar
 * esto para iniciar sesión, está usando la pieza equivocada (ver `MeseroPin`).
 *
 * Defensas heredadas del override de M14.1, que resuelve el mismo problema:
 * - **Rate limit por terminal/IP:** 6 dígitos son 10^6 combinaciones; sin límite, un tanteo
 *   automatizado encuentra un PIN válido en minutos.
 * - **Hash señuelo:** cuando el PIN no resuelve a nadie se compara igual contra un bcrypt fijo,
 *   para que el tiempo de respuesta no delate si ese PIN existe.
 * - **Mensaje único** para "no existe", "no verifica" e "inactivo": distinguirlos permitiría
 *   enumerar los PIN del local.
 */
class ResolverMeseroPorPinService
{
    /** Límite antifuerza-bruta por terminal/IP. Más holgado que el override: aquí se teclea decenas de veces por turno. */
    private const MAX_INTENTOS = 10;

    private const VENTANA_SEGUNDOS = 60;

    /** Bcrypt fijo para igualar el tiempo de respuesta cuando el PIN no resuelve a nadie. */
    private const HASH_SENUELO = '$2y$12$Kc1FEPqyOeHHI521ZT2IuuqClKye5H659TwS0yj/ZLT58fRG5li6K';

    /**
     * @throws DemasiadosIntentosException si se supera el límite de tanteo.
     * @throws PinMeseroInvalidoException si el PIN no resuelve, no verifica, o el mesero está inactivo.
     */
    public function resolver(string $pin, ?string $ip = null): Usuario
    {
        $clave = 'mesero-pin:'.($ip ?: 'sin-ip');

        if (RateLimiter::tooManyAttempts($clave, self::MAX_INTENTOS)) {
            throw new DemasiadosIntentosException(RateLimiter::availableIn($clave));
        }

        RateLimiter::hit($clave, self::VENTANA_SEGUNDOS);

        // El TenantScope acota al establecimiento activo: un PIN de otro bar no existe aquí.
        $registro = MeseroPin::with('usuario')
            ->where('pin_lookup', MeseroPin::lookup($pin))
            ->first();

        if (! $registro) {
            Hash::check($pin, self::HASH_SENUELO);

            throw new PinMeseroInvalidoException;
        }

        if (! $registro->verifica($pin)) {
            throw new PinMeseroInvalidoException;
        }

        $mesero = $registro->usuario;

        // Un mesero dado de baja no puede seguir firmando ventas: su PIN queda inerte sin que
        // nadie tenga que acordarse de retirarlo al desactivar la cuenta.
        if (! $mesero || ! $mesero->activo) {
            throw new PinMeseroInvalidoException;
        }

        // Al acertar se limpia el contador: el tanteo se penaliza, el uso normal no.
        RateLimiter::clear($clave);

        return $mesero;
    }
}
