<?php

namespace App\Models;

use App\Domain\Autorizaciones\EstadoAutorizacion;
use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DER V1.2 · Tabla 21. Solicitud de operación sensible (flujo de dos niveles).
 * entidad/entidad_id es referencia polimórfica (sin FK formal). `datos` (S9) conserva el
 * payload de la operación pendiente hasta su resolución.
 */
class Autorizacion extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'autorizaciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entidad_id' => 'integer',
            'datos' => 'array',
            'resuelta_at' => 'datetime',
        ];
    }

    /** Estado como enum (EstadoAutorizacion), fuente única del literal de estado. */
    public function estadoAutorizacion(): EstadoAutorizacion
    {
        return EstadoAutorizacion::from($this->estado);
    }

    public function usuarioSolicita()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_solicita');
    }

    public function usuarioAutoriza()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_autoriza');
    }
}
