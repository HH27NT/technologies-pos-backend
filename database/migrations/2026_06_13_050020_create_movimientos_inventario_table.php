<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 20: movimientos_inventario.
 * Ledger append-only y fuente de verdad del stock. No se edita ni borra.
 * stock_resultante permite negativos (la venta no se bloquea por stock, P2).
 * Se añade created_at (única columna fuera del listado del DER) porque un ledger
 * y el kardex/reportes requieren la marca temporal del movimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->foreignId('id_insumo')->constrained('insumos');
            $table->foreignId('id_usuario')->constrained('usuarios');
            $table->foreignId('id_orden')->nullable()->constrained('ordenes');
            $table->foreignId('id_autorizacion')->nullable()->constrained('autorizaciones');
            $table->enum('tipo', ['entrada', 'salida', 'venta', 'merma', 'rotura', 'ajuste', 'consumo_interno']);
            $table->decimal('cantidad', 12, 3);
            $table->decimal('costo_unitario', 12, 2)->nullable();
            $table->decimal('stock_resultante', 12, 3)->nullable();
            $table->text('motivo')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
