<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 5: sesiones_caja.
 * Una sola sesión `abierta` por establecimiento (índice único parcial, PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->foreignId('id_usuario_apertura')->constrained('usuarios');
            $table->foreignId('id_usuario_cierre')->nullable()->constrained('usuarios');
            $table->decimal('monto_inicial', 12, 2);
            $table->decimal('monto_sistema', 12, 2)->nullable();
            $table->decimal('monto_contado', 12, 2)->nullable();
            $table->decimal('diferencia', 12, 2)->nullable();
            $table->enum('estado', ['abierta', 'cerrada'])->default('abierta');
            $table->timestamp('abierta_at');
            $table->timestamp('cerrada_at')->nullable();
        });

        // Una sola sesión abierta por establecimiento (regla de negocio respaldada en BD).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX uq_sesiones_caja_abierta_parcial ON sesiones_caja (id_establecimiento) WHERE estado = 'abierta'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones_caja');
    }
};
