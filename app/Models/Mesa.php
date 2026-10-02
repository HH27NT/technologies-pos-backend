<?php

namespace App\Models;

use App\Support\Concerns\Auditable;
use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DER V1.2 · Tabla 6. Mesas. El estado libre/ocupada se DERIVA de la orden abierta.
 */
class Mesa extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'mesas';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'capacidad' => 'integer',
            'activa' => 'bool',
        ];
    }

    public function ordenes()
    {
        return $this->hasMany(Orden::class, 'id_mesa');
    }

    /** Orden abierta actual de la mesa (fuente del estado ocupada/libre). */
    public function ordenAbierta()
    {
        return $this->hasOne(Orden::class, 'id_mesa')->where('estado', 'abierta');
    }
}
