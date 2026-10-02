<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 12: tickets.
 * id_impresora NULL => el documento se exporta a PDF (P15).
 * contenido_json en JSONB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->foreignId('id_orden')->constrained('ordenes');
            $table->foreignId('id_usuario')->constrained('usuarios');
            $table->foreignId('id_impresora')->nullable()->constrained('impresoras');
            $table->string('folio_ticket', 20)->nullable();
            $table->jsonb('contenido_json');
            $table->enum('tipo', ['comanda', 'cobro']);
            $table->timestamp('impreso_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
