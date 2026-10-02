<?php

namespace App\Models;

use App\Support\Pin\ClaveLookup;
use App\Support\Tenant\BelongsToTenant;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * M14.1 · PIN de autorización de un usuario dentro de un establecimiento.
 *
 * El PIN nunca se persiste en claro: `pin_hash` (bcrypt) verifica y `pin_lookup` (HMAC con
 * una clave dedicada, ver self::lookup()) resuelve/indexa. El TenantScope acota toda consulta
 * al establecimiento activo, así que un PIN de otro tenant sencillamente no existe para esta
 * petición.
 */
class AutorizacionPin extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'autorizacion_pins';

    protected $guarded = ['id'];

    /** El PIN en claro jamás sale en una respuesta; los secretos tampoco. */
    protected $hidden = ['pin_hash', 'pin_lookup'];

    protected function casts(): array
    {
        return [
            'pin_hash' => 'hashed',
            'actualizado_at' => 'datetime',
        ];
    }

    /**
     * Índice determinístico del PIN: HMAC-SHA256 con una clave dedicada. Va con clave a
     * propósito — un hash desnudo de 6 dígitos se rompe con una tabla de 10^6 entradas.
     *
     * Dos decisiones deliberadas:
     *
     * 1. La clave es `pos.autorizacion.pin_lookup_key`, NO `app.key`. La app key acaba
     *    filtrándose (imágenes de Docker, .env de ejemplo, historial de git) y no debe
     *    llevarse los PIN consigo; además así se puede rotar sin invalidarlos.
     * 2. El establecimiento entra en el mensaje del HMAC. Sin él, el mismo PIN produce el
     *    mismo `pin_lookup` en todos los tenants: quien lea la BD ve qué usuarios de bares
     *    distintos comparten PIN sin necesidad de romper nada. Al ir dentro, cualquier tabla
     *    precomputada solo sirve para un establecimiento.
     *
     * Sin contexto de tenant devuelve un índice que no puede coincidir con ninguna fila
     * almacenada (todas llevan establecimiento): la resolución falla cerrada, no abierta.
     *
     * ⚠️ **El mensaje del HMAC no se toca:** cambiarlo invalidaría todos los PIN ya fijados y
     * cada autorizador tendría que volver a configurarlo. El PIN de mesero, que nació después,
     * usa su propio espacio con prefijo (ver `MeseroPin::lookup`).
     */
    public static function lookup(string $pin, ?int $idEstablecimiento = null): string
    {
        $idEstablecimiento ??= app(TenantContext::class)->id();

        return hash_hmac('sha256', $idEstablecimiento.':'.$pin, ClaveLookup::obtener());
    }

    /** Verificación real del PIN (timing-safe). */
    public function verifica(string $pin): bool
    {
        return Hash::check($pin, $this->pin_hash);
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }
}
