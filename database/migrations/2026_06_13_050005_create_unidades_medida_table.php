<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 16: unidades_medida (HÍBRIDO, P4).
 * id_establecimiento NULL = unidad predefinida global (seed, solo lectura);
 * no nula = unidad propia del establecimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_medida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->nullable()->constrained('establecimientos');
            $table->string('nombre', 50);
            $table->string('abreviacion', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_medida');
    }
};
