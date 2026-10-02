<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 13: impresoras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impresoras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->string('nombre', 50);
            $table->enum('tipo', ['ticket', 'barra', 'cocina', 'admin']);
            $table->string('conexion', 150)->nullable();
            $table->boolean('activa')->default(true);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impresoras');
    }
};
