<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 22: auditoria.
 * Bitácora append-only. id_establecimiento NULL = acción del super_admin a nivel plataforma.
 * entidad/entidad_id es referencia polimórfica (sin FK formal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->nullable()->constrained('establecimientos');
            $table->foreignId('id_usuario')->nullable()->constrained('usuarios');
            $table->string('accion', 80);
            $table->string('entidad', 50);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->jsonb('datos_antes')->nullable();
            $table->jsonb('datos_despues')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entidad', 'entidad_id'], 'idx_auditoria_entidad');
            $table->index('accion', 'idx_auditoria_accion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria');
    }
};
