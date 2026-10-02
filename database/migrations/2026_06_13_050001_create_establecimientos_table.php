<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 1: establecimientos.
 * Raíz del modelo multi-tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establecimientos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('razon_social', 150)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->text('direccion')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('logo_url', 255)->nullable();
            $table->string('zona_horaria', 50)->nullable();
            $table->string('moneda', 3)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establecimientos');
    }
};
