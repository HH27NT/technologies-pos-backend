<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * DER V1.2 · Tabla 7 (GLOBAL). Catálogo: mesa, barra, llevar.
 */
class TipoOrden extends Model
{
    protected $table = 'tipos_orden';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'activo' => 'bool',
        ];
    }

    public function ordenes()
    {
        return $this->hasMany(Orden::class, 'id_tipo_orden');
    }
}
