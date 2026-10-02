<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14.1 · PIN de autorización (reemplaza el override por contraseña de admin).
 *
 * Cada autorizador fija su propio PIN de 6 dígitos (self-service). El operador solo teclea
 * el PIN, así que este debe resolver la identidad del autorizador dentro del tenant: de ahí
 * las dos claves.
 *
 * - `pin_hash`: bcrypt con salt por fila. Es la verificación REAL (timing-safe, Hash::check).
 * - `pin_lookup`: HMAC-SHA256 de "{id_establecimiento}:{pin}" con POS_PIN_LOOKUP_KEY (clave
 *   dedicada, NO la app key). Determinístico → permite (a) resolver al autorizador con un
 *   WHERE indexado y (b) enforcar unicidad por establecimiento sin exponer el PIN. Al ir con
 *   clave, una fuga de solo-BD no permite fuerza bruta trivial del espacio de 6 dígitos; al
 *   llevar el establecimiento dentro, una tabla precomputada solo sirve para un tenant y dos
 *   bares no revelan que comparten PIN. Si la clave rota, los lookup quedan inservibles y los
 *   admins deben volver a fijar su PIN (documentado en docs/BACKEND-pin-autorizacion.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorizacion_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->constrained('establecimientos')->cascadeOnDelete();
            $table->foreignId('id_usuario')->constrained('usuarios')->cascadeOnDelete();
            $table->string('pin_hash');
            $table->string('pin_lookup', 64);
            $table->timestamp('actualizado_at')->nullable();
            $table->timestamps();

            // Un PIN por (usuario, establecimiento) y PIN único dentro del establecimiento
            // (condición para poder resolver la identidad tecleando solo el PIN).
            $table->unique(['id_usuario', 'id_establecimiento'], 'uq_pins_usuario_establecimiento');
            $table->unique(['id_establecimiento', 'pin_lookup'], 'uq_pins_establecimiento_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorizacion_pins');
    }
};
