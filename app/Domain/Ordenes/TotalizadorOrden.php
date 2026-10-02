<?php

namespace App\Domain\Ordenes;

/**
 * M11 · Calculadora PURA de los totales de una orden (P11 / D1). Sin estado, sin
 * transacción, sin acceso a BD: recibe números y devuelve números. La consumen los
 * servicios, que se encargan de leer los renglones/config y de persistir.
 *
 * Contrato (D1 · impuesto sobre el NETO):
 *   base     = subtotal − descuento          (el descuento reduce la base gravable)
 *   impuesto = aplica ? round(tasa/100 × base, 2) : 0
 *   total    = base + impuesto
 *
 * `precio_venta` NO incluye impuesto (P11): el impuesto se suma aparte como renglón
 * del total. `tasa_impuesto` es un porcentaje 0–100 (se divide entre 100).
 */
class TotalizadorOrden
{
    /**
     * @param  float  $subtotalLineas  Σ de los subtotales de los renglones ACTIVOS.
     * @param  float  $descuento  Descuento de orden (importe; los renglones cancelados ya no cuentan).
     * @param  bool  $aplicaImpuesto  configuracion_establecimiento.aplica_impuesto.
     * @param  float  $tasaImpuesto  Porcentaje 0–100.
     * @return array{subtotal: float, descuento: float, base: float, impuesto: float, total: float}
     */
    public function calcular(float $subtotalLineas, float $descuento, bool $aplicaImpuesto, float $tasaImpuesto): array
    {
        $subtotal = round($subtotalLineas, 2);
        $base = round($subtotal - $descuento, 2);
        $impuesto = $aplicaImpuesto ? round($tasaImpuesto / 100 * $base, 2) : 0.0;
        $total = round($base + $impuesto, 2);

        return [
            'subtotal' => $subtotal,
            'descuento' => round($descuento, 2),
            'base' => $base,
            'impuesto' => $impuesto,
            'total' => $total,
        ];
    }
}
