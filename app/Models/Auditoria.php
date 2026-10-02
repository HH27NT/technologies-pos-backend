<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * DER V1.2 · Tabla 22. Bitácora append-only.
 * NO usa BelongsToTenant: id_establecimiento es nullable (NULL = acción del super_admin).
 * created_at lo fija la BD (useCurrent).
 */
class Auditoria extends Model
{
    protected $table = 'auditoria';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entidad_id' => 'integer',
            'datos_antes' => 'array',
            'datos_despues' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function establecimiento()
    {
        return $this->belongsTo(Establecimiento::class, 'id_establecimiento');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }
}
