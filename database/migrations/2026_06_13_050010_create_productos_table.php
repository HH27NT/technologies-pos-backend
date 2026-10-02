<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 15: productos.
 * controla_inventario = true descuenta insumos vía receta al cobrar.
 * disponible (visible en venta) es distinto del soft delete (baja lógica).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->foreignId('id_categoria')->constrained('categorias_producto');
            $table->string('nombre', 120);
            $table->text('descripcion')->nullable();
            $table->decimal('precio_venta', 12, 2);
            $table->decimal('costo_referencia', 12, 2)->nullable();
            $table->boolean('controla_inventario')->default(false);
            $table->boolean('disponible')->default(true);
            $table->string('sku', 50)->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
