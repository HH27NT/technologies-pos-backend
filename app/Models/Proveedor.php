<?php

namespace App\Models;

use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DER V1.2 · Tabla 17. Proveedor de insumos.
 */
class Proveedor extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'proveedores';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'activo' => 'bool',
        ];
    }

    public function insumos()
    {
        return $this->hasMany(Insumo::class, 'id_proveedor');
    }
}
