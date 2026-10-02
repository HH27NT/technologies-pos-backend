<?php

namespace App\Models;

use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DER V1.2 · Tabla 1. Raíz multi-tenant. NO usa BelongsToTenant (es el tenant).
 */
class Establecimiento extends Model
{
    use Auditable, SoftDeletes;

    protected $table = 'establecimientos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'activo' => 'bool',
        ];
    }

    public function configuracion()
    {
        return $this->hasOne(ConfiguracionEstablecimiento::class, 'id_establecimiento');
    }

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'id_establecimiento');
    }

    public function mesas()
    {
        return $this->hasMany(Mesa::class, 'id_establecimiento');
    }

    public function sesionesCaja()
    {
        return $this->hasMany(SesionCaja::class, 'id_establecimiento');
    }

    public function ordenes()
    {
        return $this->hasMany(Orden::class, 'id_establecimiento');
    }

    public function categorias()
    {
        return $this->hasMany(CategoriaProducto::class, 'id_establecimiento');
    }

    public function productos()
    {
        return $this->hasMany(Producto::class, 'id_establecimiento');
    }

    public function proveedores()
    {
        return $this->hasMany(Proveedor::class, 'id_establecimiento');
    }

    public function insumos()
    {
        return $this->hasMany(Insumo::class, 'id_establecimiento');
    }

    public function impresoras()
    {
        return $this->hasMany(Impresora::class, 'id_establecimiento');
    }

    public function unidadesPropias()
    {
        return $this->hasMany(UnidadMedida::class, 'id_establecimiento');
    }
}
