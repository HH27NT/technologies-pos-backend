<?php

namespace App\Models;

use App\Support\Concerns\Auditable;
use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * DER V1.2 · Tabla 2. Configuración 1:1 del establecimiento.
 *
 * Era la única tabla operativa sin `BelongsToTenant`, y eso ya costó un bug: en el bloque del
 * PIN de mesero, `ModoTerminalCompartida` la leyó con un `first()` y un local recibió el modo
 * de otro. Con el trait, una consulta sin `where` queda acotada al tenant del contexto en vez
 * de devolver la fila de cualquiera.
 *
 * ⚠️ **El scope no exime de filtrar fuera de una petición.** `TenantScope` no aplica filtro
 * alguno cuando no hay contexto fijado —workers de cola, comandos de consola, seeders—, así que
 * ahí un `first()` seguiría devolviendo una fila ajena. Todo lo que corra en esos caminos
 * (ticket, comanda, recálculo de totales) sigue acotando por `$orden->id_establecimiento`, que
 * además es la fuente correcta: la configuración que se aplica es la del local de ESA orden, no
 * la de quien tenga el contexto abierto.
 */
class ConfiguracionEstablecimiento extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'configuracion_establecimiento';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'impresion_automatica' => 'bool',
            'terminal_compartida' => 'bool',
            'bloqueo_terminal_segundos' => 'int',
            'aplica_impuesto' => 'bool',
            'stock_minimo_global' => 'decimal:3',
            'tasa_impuesto' => 'decimal:2',
        ];
    }

    public function establecimiento()
    {
        return $this->belongsTo(Establecimiento::class, 'id_establecimiento');
    }
}
