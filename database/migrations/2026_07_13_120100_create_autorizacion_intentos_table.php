<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14.1 · Bitácora de intentos FALLIDOS de override por PIN (auditoría, M15).
 *
 * Tabla aparte de `autorizaciones` porque esa solo guarda operaciones concedidas. Aquí
 * quedan los rechazos: `pin_invalido` (no resuelve o no verifica) y `sin_permiso` (el PIN
 * resuelve a un usuario que no puede autorizar esa operación).
 *
 * NUNCA se guarda el PIN tecleado, ni en claro ni hasheado (§ Seguridad).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorizacion_intentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos')->cascadeOnDelete();
            $table->foreignId('id_usuario_solicita')->constrained('usuarios');  // el operador
            $table->string('tipo', 50);        // cancelar_item | anular_orden | entrada_stock | ajuste_stock
            $table->string('resultado', 30);   // pin_invalido | sin_permiso
            $table->string('ip', 45)->nullable();
            $table->string('terminal', 50)->nullable();
            $table->json('datos')->nullable(); // refs de la operación intentada
            $table->timestamps();

            $table->index(['id_establecimiento', 'created_at'], 'idx_intentos_tenant_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorizacion_intentos');
    }
};
