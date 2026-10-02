<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 8: ordenes.
 * Folio único por establecimiento; una sola orden `abierta` por mesa
 * (índice único parcial, PostgreSQL). Importes "congelados" en la orden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->foreignId('id_sesion_caja')->constrained('sesiones_caja');
            $table->foreignId('id_mesa')->nullable()->constrained('mesas');
            $table->foreignId('id_tipo_orden')->constrained('tipos_orden');
            $table->foreignId('id_usuario')->constrained('usuarios');
            $table->string('folio', 20);
            $table->enum('estado', ['abierta', 'pagada', 'anulada'])->default('abierta');
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('impuesto', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notas')->nullable();
            $table->timestamp('abierta_at');
            $table->timestamp('cerrada_at')->nullable();

            $table->unique(['id_establecimiento', 'folio'], 'uq_ordenes_establecimiento_folio');
        });

        // Una sola orden abierta por mesa (regla de negocio respaldada en BD).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX uq_ordenes_abierta_mesa_parcial ON ordenes (id_mesa) WHERE estado = 'abierta' AND id_mesa IS NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes');
    }
};
