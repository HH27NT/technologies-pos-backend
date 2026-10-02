<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PIN de identificación del mesero en terminal compartida.
 *
 * ⚠️ **Tabla SEPARADA de `autorizacion_pins` a propósito, no por comodidad.** El PIN de
 * autorización (M14.1) aprueba dinero: anula órdenes, ajusta inventario. El PIN de mesero se
 * teclea decenas de veces por turno, en una tablet de la barra, a la vista de compañeros y
 * clientes. Compartir el secreto entre ambos usos significaría que un gerente que atiende
 * mesas expone en público la llave que autoriza anulaciones.
 *
 * De ahí que sean secretos distintos, con tablas distintas, y que el flujo de override
 * **rechace** un PIN de mesero aunque coincidan los dígitos (ver `OverrideAutorizacionService`
 * y su test). Este PIN **jamás** autoriza: identifica y atribuye, nada más.
 *
 * Se reutiliza el patrón criptográfico de M14.1, que ya está probado:
 * - `pin_hash`: bcrypt con salt por fila. Es la verificación real (timing-safe, Hash::check).
 * - `pin_lookup`: HMAC-SHA256 de "{id_establecimiento}:{pin}" con POS_PIN_LOOKUP_KEY (clave
 *   dedicada, NO la app key). Determinístico → resuelve la identidad con un WHERE indexado y
 *   enforza unicidad por establecimiento sin exponer el PIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mesero_pins', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('id_establecimiento')->constrained('establecimientos')->cascadeOnDelete();
            $tabla->foreignId('id_usuario')->constrained('usuarios')->cascadeOnDelete();
            $tabla->string('pin_hash');
            $tabla->string('pin_lookup', 64);
            $tabla->timestamp('actualizado_at')->nullable();
            $tabla->timestamps();

            // Un PIN por (mesero, establecimiento), y único dentro del establecimiento: es la
            // condición para poder resolver a la persona tecleando solo 6 dígitos.
            $tabla->unique(['id_usuario', 'id_establecimiento'], 'uq_mesero_pins_usuario_establecimiento');
            $tabla->unique(['id_establecimiento', 'pin_lookup'], 'uq_mesero_pins_establecimiento_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesero_pins');
    }
};
