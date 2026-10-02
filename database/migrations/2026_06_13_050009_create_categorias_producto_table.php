<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 14: categorias_producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_producto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->string('nombre', 80);
            $table->integer('orden_display')->nullable();
            $table->boolean('activo')->default(true);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_producto');
    }
};
