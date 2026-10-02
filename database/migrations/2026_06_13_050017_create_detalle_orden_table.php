<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 9: detalle_orden.
 * Único CASCADE autorizado del modelo: id_orden ON DELETE CASCADE.
 * `enviado` (V1.2): la cantidad solo se modifica mientras enviado = false;
 * pasa a true al confirmar la comanda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_orden', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->foreignId('id_orden')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('id_producto')->constrained('productos');
            $table->foreignId('id_autorizacion')->nullable()->constrained('autorizaciones');
            $table->decimal('cantidad', 10, 3);
            $table->decimal('precio_unitario', 12, 2);
            $table->decimal('descuento_item', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('enviado')->default(false);
            $table->enum('estado_item', ['activo', 'cancelado'])->default('activo');
            $table->timestamp('cancelado_at')->nullable();
            $table->string('notas', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_orden');
    }
};
