<?php

namespace App\Models;

use App\Domain\Ordenes\EstadoOrden;
use App\Support\Concerns\GeneraFolio;
use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DER V1.2 · Tabla 8. Núcleo transaccional de la venta. Importes "congelados".
 * GeneraFolio queda disponible para el folio continuo por tenant (lo usa Sprint 7).
 */
class Orden extends Model
{
    use BelongsToTenant, GeneraFolio, HasFactory;

    protected $table = 'ordenes';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'descuento' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'impuesto' => 'decimal:2',
            'total' => 'decimal:2',
            'abierta_at' => 'datetime',
            'cerrada_at' => 'datetime',
        ];
    }

    /** Estado como enum (EstadoOrden), fuente única del literal de estado (S7). */
    public function estadoOrden(): EstadoOrden
    {
        return EstadoOrden::from($this->estado);
    }

    public function sesionCaja()
    {
        return $this->belongsTo(SesionCaja::class, 'id_sesion_caja');
    }

    public function mesa()
    {
        return $this->belongsTo(Mesa::class, 'id_mesa');
    }

    public function tipoOrden()
    {
        return $this->belongsTo(TipoOrden::class, 'id_tipo_orden');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    /**
     * Persona que firmó la venta con su PIN en terminal compartida. Nulo en el modo normal.
     * Para saber de quién es la venta usa `MeseroEfectivo`, no este campo a secas.
     */
    public function mesero()
    {
        return $this->belongsTo(Usuario::class, 'id_mesero');
    }

    public function detalles()
    {
        return $this->hasMany(DetalleOrden::class, 'id_orden');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'id_orden');
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'id_orden');
    }

    public function movimientosInventario()
    {
        return $this->hasMany(MovimientoInventario::class, 'id_orden');
    }
}
