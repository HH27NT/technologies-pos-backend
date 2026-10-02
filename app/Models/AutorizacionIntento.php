<?php

namespace App\Models;

use App\Domain\Autorizaciones\ResultadoIntento;
use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * M14.1 · Intento FALLIDO de override por PIN (auditoría). Se registra fuera de la
 * transacción de la operación, de modo que el rechazo queda grabado aunque la petición
 * termine en error. Nunca contiene el PIN tecleado.
 */
class AutorizacionIntento extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'autorizacion_intentos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'datos' => 'array',
            'resultado' => ResultadoIntento::class,
        ];
    }

    public function usuarioSolicita()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_solicita');
    }
}
