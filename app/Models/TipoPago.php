<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * DER V1.2 · Tabla 10 (GLOBAL). Catálogo: efectivo, tarjeta, transferencia.
 */
class TipoPago extends Model
{
    protected $table = 'tipos_pago';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'activo' => 'bool',
        ];
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'id_tipo_pago');
    }
}
