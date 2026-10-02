<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 19: recetas_producto (BOM, puente producto↔insumo).
 * Un insumo no se repite en la misma receta: UNIQUE(id_producto, id_insumo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recetas_producto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->foreignId('id_producto')->constrained('productos');
            $table->foreignId('id_insumo')->constrained('insumos');
            $table->decimal('cantidad', 10, 3);

            $table->unique(['id_producto', 'id_insumo'], 'uq_recetas_producto_insumo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recetas_producto');
    }
};
