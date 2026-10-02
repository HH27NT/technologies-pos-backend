<?php

namespace App\Models;

use App\Support\Concerns\GeneraFolio;
use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DER V1.2 · Tabla 12. Comanda o ticket de cobro. id_impresora NULL => PDF (P15).
 * GeneraFolio produce el folio_ticket continuo por tenant (S10).
 */
class Ticket extends Model
{
    use BelongsToTenant, GeneraFolio, HasFactory;

    protected $table = 'tickets';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'contenido_json' => 'array',
            'impreso_at' => 'datetime',
        ];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'id_orden');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function impresora()
    {
        return $this->belongsTo(Impresora::class, 'id_impresora');
    }
}
