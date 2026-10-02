<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 2: configuracion_establecimiento (1:1 con establecimientos).
 * Incluye los ajustes V1.2: aplica_impuesto y tasa_impuesto (P11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_establecimiento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->string('nombre_comercial', 150)->nullable();
            $table->string('telefono_ticket', 20)->nullable();
            $table->text('direccion_ticket')->nullable();
            $table->boolean('impresion_automatica')->default(false);
            $table->decimal('stock_minimo_global', 12, 3)->nullable();
            $table->boolean('aplica_impuesto')->default(false);
            $table->decimal('tasa_impuesto', 5, 2)->nullable();
            $table->timestamps();

            $table->unique('id_establecimiento', 'uq_configuracion_establecimiento');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_establecimiento');
    }
};
