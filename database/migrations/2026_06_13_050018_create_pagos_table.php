<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 11: pagos.
 * Soporta pago dividido (N pagos por orden).
 * `propina` se conserva RESERVADA para V2; ningún proceso de V1 la escribe (P9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos');
            $table->foreignId('id_orden')->constrained('ordenes');
            $table->foreignId('id_tipo_pago')->constrained('tipos_pago');
            $table->foreignId('id_usuario')->constrained('usuarios');
            $table->decimal('monto', 12, 2);
            $table->decimal('propina', 12, 2)->nullable(); // Reservado V2 (P9): sin uso en V1.
            $table->string('referencia', 100)->nullable();
            $table->timestamp('pagado_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
