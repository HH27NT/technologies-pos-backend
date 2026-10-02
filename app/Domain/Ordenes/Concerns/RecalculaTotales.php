<?php

namespace App\Domain\Ordenes\Concerns;

use App\Domain\Ordenes\EstadoItem;
use App\Domain\Ordenes\TotalizadorOrden;
use App\Models\ConfiguracionEstablecimiento;
use App\Models\Orden;

/**
 * Recalcula y persiste los totales congelados de la orden a partir de sus renglones
 * ACTIVOS y la configuración fiscal del establecimiento, delegando el cálculo a la
 * calculadora pura TotalizadorOrden (P11 / D1). Lo comparten todos los servicios que
 * mutan renglones o el descuento (agregar/modificar/cancelar/descuento).
 *
 * Debe invocarse DENTRO de la transacción del servicio: deja subtotal/impuesto/total
 * consistentes con el descuento ya fijado en la orden.
 */
trait RecalculaTotales
{
    protected function recalcularTotales(Orden $orden): void
    {
        $subtotalLineas = (float) $orden->detalles()
            ->where('estado_item', EstadoItem::Activo->value)
            ->sum('subtotal');

        // configuracion_establecimiento no usa TenantScope: se acota por la orden.
        $config = ConfiguracionEstablecimiento::query()
            ->where('id_establecimiento', $orden->id_establecimiento)
            ->first();

        $totales = (new TotalizadorOrden)->calcular(
            $subtotalLineas,
            (float) $orden->descuento,
            (bool) ($config?->aplica_impuesto ?? false),
            (float) ($config?->tasa_impuesto ?? 0),
        );

        $orden->fill([
            'subtotal' => $totales['subtotal'],
            'impuesto' => $totales['impuesto'],
            'total' => $totales['total'],
        ])->save();
    }
}
