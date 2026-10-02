<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 7: tipos_orden (GLOBAL, sin tenant).
 * Catálogo compartido: mesa, barra, llevar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_orden', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 50);
            $table->boolean('activo')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_orden');
    }
};
