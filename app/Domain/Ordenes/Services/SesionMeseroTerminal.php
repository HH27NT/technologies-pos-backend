<?php

namespace App\Domain\Ordenes\Services;

use App\Models\Usuario;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Sesión efímera del mesero dentro de la terminal compartida.
 *
 * **Por qué existe.** El PIN se teclea una vez y el mesero queda "activo" unos minutos; sería
 * insufrible pedirlo en cada toque. Pero si el POS enviara `id_mesero` a secas al crear la
 * orden, el campo sería **falsificable**: bastaría con editar la petición para atribuirle la
 * venta a cualquiera, sin conocer ningún PIN, y el reporte por mesero dejaría de significar
 * nada. Toda la función depende de que ese número no se pueda inventar desde el cliente.
 *
 * Así que identificar **emite un token opaco** que el POS adjunta al crear la orden y al
 * cobrar. El token vive en el cache del servidor: el cliente no puede fabricarlo ni alterar a
 * quién apunta.
 *
 * No es una sesión de autenticación: no concede permisos, no emite token de Sanctum y no
 * cambia quién es `Auth::user()` (sigue siendo la cuenta de la terminal). Solo responde
 * "¿a quién se le apunta esta venta?".
 *
 * Caduca sola con el mismo plazo que el auto-bloqueo de la tablet, para que no sobreviva a la
 * persona: si el mesero se aleja y la pantalla se bloquea, su firma ya no sirve.
 */
class SesionMeseroTerminal
{
    private const PREFIJO = 'mesero-terminal:';

    public function __construct(private readonly TenantContext $tenant) {}

    /** Emite el token de la sesión efímera. Devuelve el valor opaco que el POS debe adjuntar. */
    public function emitir(Usuario $mesero, int $segundos): string
    {
        $token = Str::random(48);

        Cache::put(self::PREFIJO.$token, [
            'id_mesero' => $mesero->id,
            'id_establecimiento' => $this->tenant->id(),
        ], $segundos);

        return $token;
    }

    /**
     * Resuelve el token al id del mesero, o null si expiró, no existe o es de otro tenant.
     *
     * La comprobación de establecimiento no es redundante con el TenantScope: el cache es
     * global, así que sin ella un token válido en un bar serviría en otro.
     */
    public function resolver(?string $token): ?int
    {
        if (blank($token)) {
            return null;
        }

        $datos = Cache::get(self::PREFIJO.$token);

        if (! is_array($datos) || ($datos['id_establecimiento'] ?? null) !== $this->tenant->id()) {
            return null;
        }

        return $datos['id_mesero'] ?? null;
    }

    /** Invalida el token (cierre de sesión del mesero o bloqueo manual de la terminal). */
    public function revocar(?string $token): void
    {
        if (filled($token)) {
            Cache::forget(self::PREFIJO.$token);
        }
    }
}
