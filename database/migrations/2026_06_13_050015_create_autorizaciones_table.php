<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 21: autorizaciones.
 * Se crea antes de detalle_orden y movimientos_inventario porque ambas la referencian
 * por FK (id_autorizacion), mientras que autorizaciones solo apunta a la entidad
 * afectada mediante la referencia polimórfica entidad/entidad_id (sin FK formal).
 * `tipo` se modela como VARCHAR por ser una lista abierta en el DER
 * (cancelar_item, anular_orden, ajuste_stock, entrada_stock, ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->foreignId('id_usuario_solicita')->constrained('usuarios');
            $table->foreignId('id_usuario_autoriza')->nullable()->constrained('usuarios');
            $table->string('tipo', 50);
            $table->string('entidad', 50);
            $table->unsignedBigInteger('entidad_id');
            $table->enum('estado', ['pendiente', 'aprobada', 'rechazada'])->default('pendiente');
            $table->text('motivo')->nullable();
            $table->timestamp('resuelta_at')->nullable();
            $table->timestamps();

            $table->index(['entidad', 'entidad_id'], 'idx_autorizaciones_entidad');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorizaciones');
    }
};
