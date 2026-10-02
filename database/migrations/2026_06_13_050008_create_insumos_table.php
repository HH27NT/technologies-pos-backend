<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 18: insumos.
 * stock_actual es cache; la fuente de verdad es movimientos_inventario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->foreignId('id_unidad_medida')->constrained('unidades_medida');
            $table->foreignId('id_proveedor')->nullable()->constrained('proveedores');
            $table->string('nombre', 120);
            $table->decimal('stock_actual', 12, 3)->default(0);
            $table->decimal('stock_minimo', 12, 3)->nullable();
            $table->decimal('costo_unitario', 12, 2)->nullable();
            $table->boolean('activo')->default(true);
            $table->softDeletes();
        });

        // Índice parcial de stock bajo para el reporte de inventario (PostgreSQL, §16/§3.5).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX idx_insumos_stock_bajo_parcial ON insumos (id_establecimiento) WHERE stock_actual <= stock_minimo');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('insumos');
    }
};
