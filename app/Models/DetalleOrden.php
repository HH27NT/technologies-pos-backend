<?php

namespace App\Models;

use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DER V1.2 · Tabla 9. Renglón de orden.
 * `enviado` (V1.2): la cantidad solo se modifica mientras enviado = false.
 */
class DetalleOrden extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'detalle_orden';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'precio_unitario' => 'decimal:2',
            'descuento_item' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'enviado' => 'bool',
            'cancelado_at' => 'datetime',
        ];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'id_orden');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'id_producto');
    }

    public function autorizacion()
    {
        return $this->belongsTo(Autorizacion::class, 'id_autorizacion');
    }
}
