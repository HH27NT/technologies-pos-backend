<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M08 · Clase del insumo: cómo se descuenta su existencia.
 *
 *  - `controlado` (default): se descuenta por receta al vender. Alcohol, refrescos,
 *    latas, carne, café: lo que tiene valor y se quiere cuadrar.
 *  - `consumo`: NO se descuenta por venta. Entra por compra y se corrige con conteo
 *    físico + ajuste. Tamarindo, chamoy, sal, hielo: lo que se echa al tanteo.
 *
 * Nace de un problema real de barra: nadie sabe si en un preparado van 4 g de
 * tamarindo o 10. Poner una cantidad inventada en la receta propaga el error al costo
 * y al inventario, y la desviación acumulada termina volviendo inservible el stock de
 * TODO lo demás, que es justo lo que sí se quiere cuadrar. Se acepta perder el costo
 * del condimento en el reporte de margen: es un redondeo, y esa precisión era falsa.
 *
 * String y no enum de base: alterar un enum en PostgreSQL exige migración de tipo, y
 * el conjunto de valores se valida en el Form Request (misma convención que
 * `impresoras.tipo`). Default `controlado` para no tocar los insumos ya existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insumos', function (Blueprint $tabla) {
            $tabla->string('tipo', 20)->default('controlado')->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('insumos', function (Blueprint $tabla) {
            $tabla->dropColumn('tipo');
        });
    }
};
