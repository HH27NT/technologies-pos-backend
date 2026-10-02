<?php

namespace App\Models;

use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DER V1.2 · Tabla 20. Ledger append-only del stock (fuente de verdad).
 * No se edita ni borra; created_at lo fija la BD (useCurrent).
 */
class MovimientoInventario extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'movimientos_inventario';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'costo_unitario' => 'decimal:2',
            'stock_resultante' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    public function insumo()
    {
        return $this->belongsTo(Insumo::class, 'id_insumo');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'id_orden');
    }

    public function autorizacion()
    {
        return $this->belongsTo(Autorizacion::class, 'id_autorizacion');
    }
}
