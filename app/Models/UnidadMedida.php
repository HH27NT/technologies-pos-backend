<?php

namespace App\Models;

use App\Support\Tenant\IncluyeGlobales;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DER V1.2 · Tabla 16 (HÍBRIDO, P4). Usa IncluyeGlobales: las consultas devuelven
 * las unidades propias del tenant + las predefinidas globales (id_establecimiento NULL).
 */
class UnidadMedida extends Model
{
    use HasFactory, IncluyeGlobales;

    protected $table = 'unidades_medida';

    public $timestamps = false;

    protected $guarded = ['id'];

    public function establecimiento()
    {
        return $this->belongsTo(Establecimiento::class, 'id_establecimiento');
    }

    public function insumos()
    {
        return $this->hasMany(Insumo::class, 'id_unidad_medida');
    }

    /** True si es una unidad predefinida global (solo lectura para el tenant). */
    public function esGlobal(): bool
    {
        return $this->id_establecimiento === null;
    }
}
