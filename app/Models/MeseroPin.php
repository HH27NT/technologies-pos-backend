<?php

namespace App\Models;

use App\Support\Pin\ClaveLookup;
use App\Support\Tenant\BelongsToTenant;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * PIN de identificación del mesero en terminal compartida.
 *
 * Mismo patrón criptográfico que `AutorizacionPin` (bcrypt para verificar, HMAC para resolver),
 * pero **secreto distinto y tabla distinta**: este se teclea en público decenas de veces por
 * turno y jamás autoriza nada. Ver la migración de `mesero_pins` para el razonamiento completo.
 */
class MeseroPin extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'mesero_pins';

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
     * Índice determinístico del PIN.
     *
     * El mensaje lleva el prefijo `mesero:` además del establecimiento. Sin él, la misma
     * persona que usara los mismos 6 dígitos para autorizar y para firmar produciría el
     * **mismo** `pin_lookup` en las dos tablas: quien leyera la BD sabría que el PIN público
     * de la barra abre también la puerta de las anulaciones. Con el prefijo, ambas columnas
     * son incomparables.
     *
     * Sin contexto de tenant devuelve un índice que no puede coincidir con ninguna fila
     * almacenada: la resolución falla cerrada, no abierta.
     */
    public static function lookup(string $pin, ?int $idEstablecimiento = null): string
    {
        $idEstablecimiento ??= app(TenantContext::class)->id();

        return hash_hmac('sha256', 'mesero:'.$idEstablecimiento.':'.$pin, ClaveLookup::obtener());
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
