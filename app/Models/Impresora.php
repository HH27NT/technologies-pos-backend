<?php

namespace App\Models;

use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DER V1.2 · Tabla 13. Impresora física: ticket, barra, cocina, admin.
 */
class Impresora extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'impresoras';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'activa' => 'bool',
        ];
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'id_impresora');
    }
}
