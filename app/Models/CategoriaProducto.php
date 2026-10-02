<?php

namespace App\Models;

use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DER V1.2 · Tabla 14. Categoría de producto.
 */
class CategoriaProducto extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'categorias_producto';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'orden_display' => 'integer',
            'activo' => 'bool',
        ];
    }

    public function productos()
    {
        return $this->hasMany(Producto::class, 'id_categoria');
    }
}
