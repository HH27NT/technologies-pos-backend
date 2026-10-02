<?php

namespace App\Models;

use App\Support\Concerns\Auditable;
use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DER V1.2 · Tabla 18. Artículo de inventario.
 * stock_actual es cache; la fuente de verdad es movimientos_inventario.
 */
class Insumo extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'insumos';

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * El default de `tipo` se declara también aquí, no solo en la migración: sin esto
     * el modelo recién creado lo lleva nulo en memoria y el Resource devuelve null en
     * la respuesta del alta, aunque en la base ya diga 'controlado'.
     */
    protected $attributes = ['tipo' => 'controlado'];

    protected function casts(): array
    {
        return [
            'stock_actual' => 'decimal:3',
            'stock_minimo' => 'decimal:3',
            'costo_unitario' => 'decimal:2',
            'activo' => 'bool',
        ];
    }

    public function unidadMedida()
    {
        return $this->belongsTo(UnidadMedida::class, 'id_unidad_medida');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'id_proveedor');
    }

    public function recetas()
    {
        return $this->hasMany(RecetaProducto::class, 'id_insumo');
    }

    public function movimientos()
    {
        return $this->hasMany(MovimientoInventario::class, 'id_insumo');
    }
}
