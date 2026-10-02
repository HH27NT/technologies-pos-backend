<?php

namespace App\Domain\Ordenes\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Ordenes\Concerns\RecalculaTotales;
use App\Domain\Ordenes\EstadoItem;
use App\Models\Orden;
use App\Support\Exceptions\DescuentoInvalidoException;
use App\Support\Exceptions\OrdenNoModificableException;
use Illuminate\Support\Facades\DB;

/**
 * M11 · Descuento de orden del cajero. Atómico y auditado (§15).
 *
 * Monto fijo (la columna `descuento` es importe), a nivel orden, sin autorización
 * (P10: ADMIN y OPERADOR lo aplican directo). No puede exceder el subtotal de los
 * renglones activos (→ DescuentoInvalidoException). Recalcula impuesto/total con el
 * impuesto sobre el neto (D1).
 */
class AplicarDescuentoService
{
    use RecalculaTotales;

    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function aplicar(Orden $orden, array $datos): Orden
    {
        return DB::transaction(function () use ($orden, $datos) {
            $orden = Orden::whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if (! $orden->estadoOrden()->esModificable()) {
                throw new OrdenNoModificableException;
            }

            $descuento = round((float) $datos['descuento'], 2);

            $subtotalActivo = (float) $orden->detalles()
                ->where('estado_item', EstadoItem::Activo->value)
                ->sum('subtotal');

            if ($descuento > $subtotalActivo) {
                throw new DescuentoInvalidoException;
            }

            $antes = (float) $orden->descuento;
            $orden->descuento = $descuento;
            $orden->save();

            $this->recalcularTotales($orden);

            $this->auditoria->registrar(
                accion: 'orden.descuento',
                entidad: 'ordenes',
                entidadId: $orden->id,
                datosAntes: ['descuento' => $antes],
                datosDespues: ['descuento' => $descuento, 'total' => (float) $orden->total],
            );

            return $orden;
        });
    }
}
