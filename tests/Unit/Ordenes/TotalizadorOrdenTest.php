<?php

namespace Tests\Unit\Ordenes;

use App\Domain\Ordenes\TotalizadorOrden;
use PHPUnit\Framework\TestCase;

/**
 * M11 · Calculadora pura: impuesto sobre el NETO (subtotal − descuento, P11/D1), sin
 * impuesto cuando aplica_impuesto=false, y redondeo a 2 decimales. No toca BD.
 */
class TotalizadorOrdenTest extends TestCase
{
    private TotalizadorOrden $totalizador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totalizador = new TotalizadorOrden;
    }

    public function test_impuesto_sobre_el_neto_con_descuento(): void
    {
        // subtotal 100, descuento 20 => base 80; impuesto 16% de 80 = 12.80; total 92.80.
        $totales = $this->totalizador->calcular(100, 20, true, 16);

        $this->assertSame(100.0, $totales['subtotal']);
        $this->assertSame(80.0, $totales['base']);
        $this->assertSame(12.80, $totales['impuesto']);
        $this->assertSame(92.80, $totales['total']);
    }

    public function test_sin_impuesto_cuando_no_aplica(): void
    {
        // aplica_impuesto=false => impuesto 0 aunque haya tasa; total = base.
        $totales = $this->totalizador->calcular(100, 20, false, 16);

        $this->assertSame(0.0, $totales['impuesto']);
        $this->assertSame(80.0, $totales['total']);
    }

    public function test_impuesto_se_redondea_a_dos_decimales(): void
    {
        // 16% de 33.33 = 5.3328 => 5.33; total 33.33 + 5.33 = 38.66.
        $totales = $this->totalizador->calcular(33.33, 0, true, 16);

        $this->assertSame(5.33, $totales['impuesto']);
        $this->assertSame(38.66, $totales['total']);
    }

    public function test_sin_descuento_grava_todo_el_subtotal(): void
    {
        $totales = $this->totalizador->calcular(200, 0, true, 10);

        $this->assertSame(200.0, $totales['base']);
        $this->assertSame(20.0, $totales['impuesto']);
        $this->assertSame(220.0, $totales['total']);
    }
}
