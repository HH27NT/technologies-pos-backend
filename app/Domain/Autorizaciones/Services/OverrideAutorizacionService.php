<?php

namespace App\Domain\Autorizaciones\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Autorizaciones\EstadoAutorizacion;
use App\Domain\Autorizaciones\MetodoAutorizacion;
use App\Domain\Autorizaciones\ResultadoIntento;
use App\Domain\Autorizaciones\TipoAutorizacion;
use App\Models\Autorizacion;
use App\Models\AutorizacionIntento;
use App\Models\AutorizacionPin;
use App\Models\Usuario;
use App\Support\Exceptions\AutorizadorSinPermisoException;
use App\Support\Exceptions\DemasiadosIntentosException;
use App\Support\Exceptions\PinAutorizacionInvalidoException;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * M14.1 · Override de autorización por PIN de 6 dígitos ("override de gerente").
 *
 * El OPERADOR ejecuta una operación sensible al instante tecleando SOLO el PIN de un
 * autorizador (+ motivo): el PIN resuelve la identidad dentro del tenant → se valida su
 * permiso → se ejecuta la acción de dominio (reusada de S7/S9) → se registra en
 * `autorizaciones` con estado=aprobada y metodo=override. Los rechazos van a
 * `autorizacion_intentos`.
 *
 * Sustituye al override por usuario+contraseña de admin: pedirle a un operador que teclee
 * las credenciales de acceso del admin las expone (y sirven para iniciar sesión). El PIN es
 * un secreto acotado: solo autoriza operaciones, nunca emite token ni sesión.
 *
 * La ejecución ocurre en una transacción: si la acción de dominio lanza una excepción de
 * estado (orden ya no modificable, stock insuficiente...), se revierte también la
 * autorización, sin dejar un registro `aprobada` huérfano (atomicidad §15).
 */
class OverrideAutorizacionService
{
    /** Límite antifuerza-bruta por operador+IP (equivalente a throttle:5,1). */
    private const MAX_INTENTOS = 5;

    private const VENTANA_SEGUNDOS = 60;

    /**
     * Hash bcrypt fijo para comparar cuando el PIN no resuelve a nadie: iguala el tiempo de
     * respuesta con el del caso "existe pero no verifica" (evita fuga por timing).
     */
    private const HASH_SENUELO = '$2y$12$Kc1FEPqyOeHHI521ZT2IuuqClKye5H659TwS0yj/ZLT58fRG5li6K';

    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    /**
     * Verifica el PIN, ejecuta la acción y registra la autorización aprobada.
     *
     * @param  array{autorizacion_pin?:string,motivo?:string,terminal?:string}  $payload  Subset validado del request.
     * @param  int  $entidadId  Sujeto de la operación (item/orden/insumo), como en el flujo asíncrono.
     * @param  array  $datos  Refs de la operación a persistir en `autorizaciones.datos`.
     * @param  Closure(Autorizacion):void  $ejecutar  Acción de dominio; recibe la autorización ya creada para enlazar id_autorizacion.
     * @param  string|null  $ip  IP del operador (clave del throttle).
     */
    public function ejecutar(
        TipoAutorizacion $tipo,
        array $payload,
        int $entidadId,
        array $datos,
        Closure $ejecutar,
        ?string $ip = null,
    ): Autorizacion {
        $clave = $this->claveThrottle($ip);

        if (RateLimiter::tooManyAttempts($clave, self::MAX_INTENTOS)) {
            throw new DemasiadosIntentosException(RateLimiter::availableIn($clave));
        }

        RateLimiter::hit($clave, self::VENTANA_SEGUNDOS);

        $autorizador = $this->resolverAutorizador($tipo, (string) ($payload['autorizacion_pin'] ?? ''), $payload, $datos, $ip);

        // PIN válido y con permiso: el operador legítimo no queda penalizado por ráfagas de
        // overrides correctos; solo los intentos fallidos siguen contando.
        RateLimiter::clear($clave);

        return DB::transaction(function () use ($tipo, $payload, $entidadId, $datos, $ejecutar, $autorizador) {
            $autorizacion = Autorizacion::create([
                'id_usuario_solicita' => Auth::id(),
                'id_usuario_autoriza' => $autorizador->id,
                'tipo' => $tipo->value,
                'entidad' => $tipo->entidad(),
                'entidad_id' => $entidadId,
                'estado' => EstadoAutorizacion::Aprobada->value,
                'metodo' => MetodoAutorizacion::Override->value,
                'motivo' => $payload['motivo'] ?? null,
                'datos' => $datos,
                'resuelta_at' => now(),
            ]);

            $ejecutar($autorizacion);

            $this->auditoria->registrar(
                accion: 'autorizacion.override',
                entidad: 'autorizaciones',
                entidadId: $autorizacion->id,
                datosDespues: [
                    'tipo' => $tipo->value,
                    'entidad' => $tipo->entidad(),
                    'entidad_id' => $entidadId,
                    'id_usuario_autoriza' => $autorizador->id,
                    'metodo' => MetodoAutorizacion::Override->value,
                    // Nunca se persiste el PIN (§ Seguridad).
                ],
            );

            return $autorizacion;
        });
    }

    /**
     * Resuelve al autorizador a partir del PIN y valida que pueda autorizar ESTA operación.
     * El TenantScope de AutorizacionPin/Usuario acota la búsqueda al establecimiento activo,
     * así que un PIN de otro tenant simplemente no resuelve. Cada rechazo deja rastro en
     * `autorizacion_intentos` antes de propagarse.
     */
    private function resolverAutorizador(TipoAutorizacion $tipo, string $pin, array $payload, array $datos, ?string $ip): Usuario
    {
        $registro = AutorizacionPin::where('pin_lookup', AutorizacionPin::lookup($pin))->first();

        // Timing-safe: siempre se ejecuta un Hash::check, resuelva el PIN o no.
        $verifica = Hash::check($pin, $registro?->pin_hash ?? self::HASH_SENUELO);

        $autorizador = $registro !== null && $verifica
            ? Usuario::where('id', $registro->id_usuario)->where('activo', true)->first()
            : null;

        if ($autorizador === null) {
            $this->registrarIntento($tipo, ResultadoIntento::PinInvalido, $payload, $datos, $ip);

            throw new PinAutorizacionInvalidoException;
        }

        if (! $autorizador->can($tipo->permiso())) {
            $this->registrarIntento($tipo, ResultadoIntento::SinPermiso, $payload, $datos, $ip);

            throw new AutorizadorSinPermisoException;
        }

        return $autorizador;
    }

    /**
     * Deja el rechazo en la bitácora de intentos. Se escribe FUERA de la transacción de la
     * operación (que ni siquiera llega a abrirse): el rastro debe sobrevivir al error.
     */
    private function registrarIntento(TipoAutorizacion $tipo, ResultadoIntento $resultado, array $payload, array $datos, ?string $ip): void
    {
        AutorizacionIntento::create([
            'id_usuario_solicita' => Auth::id(),
            'tipo' => $tipo->value,
            'resultado' => $resultado->value,
            'ip' => $ip,
            'terminal' => $payload['terminal'] ?? null,
            'datos' => $datos,  // Refs de la operación intentada; nunca el PIN tecleado.
        ]);
    }

    private function claveThrottle(?string $ip): string
    {
        return 'override:'.(Auth::id() ?? 'anon').':'.($ip ?? 'sin-ip');
    }
}
