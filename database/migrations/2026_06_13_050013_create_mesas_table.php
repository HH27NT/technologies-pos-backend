<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 6: mesas.
 * El estado ocupada/libre se deriva de la orden abierta; aquí solo el catálogo.
 * Número único por establecimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mesas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->integer('numero');
            $table->string('nombre', 50)->nullable();
            $table->string('zona', 50)->nullable();
            $table->integer('capacidad')->nullable();
            $table->boolean('activa')->default(true);
            $table->softDeletes();

            $table->unique(['id_establecimiento', 'numero'], 'uq_mesas_establecimiento_numero');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesas');
    }
};
