<?php

namespace App\Models;

use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DER V1.2 · Tabla 5. Sesión de caja. Lifecycle por abierta_at/cerrada_at (sin timestamps Laravel).
 */
class SesionCaja extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'sesiones_caja';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'monto_inicial' => 'decimal:2',
            'monto_sistema' => 'decimal:2',
            'monto_contado' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'abierta_at' => 'datetime',
            'cerrada_at' => 'datetime',
        ];
    }

    public function usuarioApertura()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_apertura');
    }

    public function usuarioCierre()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_cierre');
    }

    public function ordenes()
    {
        return $this->hasMany(Orden::class, 'id_sesion_caja');
    }
}
