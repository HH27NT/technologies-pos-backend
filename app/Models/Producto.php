<?php

namespace App\Models;

use App\Support\Concerns\Auditable;
use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DER V1.2 · Tabla 15. Ítem vendible. controla_inventario descuenta insumos vía receta.
 */
class Producto extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'productos';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'precio_venta' => 'decimal:2',
            'costo_referencia' => 'decimal:2',
            'controla_inventario' => 'bool',
            'disponible' => 'bool',
        ];
    }

    public function categoria()
    {
        return $this->belongsTo(CategoriaProducto::class, 'id_categoria');
    }

    public function detalles()
    {
        return $this->hasMany(DetalleOrden::class, 'id_producto');
    }

    public function recetas()
    {
        return $this->hasMany(RecetaProducto::class, 'id_producto');
    }

    /** Insumos de la receta (BOM) con el atributo cantidad. */
    public function insumos()
    {
        return $this->belongsToMany(Insumo::class, 'recetas_producto', 'id_producto', 'id_insumo')
            ->withPivot('cantidad');
    }
}
