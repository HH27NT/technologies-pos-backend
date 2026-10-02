<?php

namespace App\Models;

use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DER V1.2 · Tabla 11. Pago de una orden (soporta pago dividido).
 * `propina` reservada V2 (P9): sin uso en V1.
 */
class Pago extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'pagos';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'propina' => 'decimal:2',
            'pagado_at' => 'datetime',
        ];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'id_orden');
    }

    public function tipoPago()
    {
        return $this->belongsTo(TipoPago::class, 'id_tipo_pago');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    /** Persona que firmó el cobro con su PIN en terminal compartida. Nulo en el modo normal. */
    public function mesero()
    {
        return $this->belongsTo(Usuario::class, 'id_mesero');
    }
}
