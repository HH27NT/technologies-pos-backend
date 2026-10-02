<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 8 (D1) · Idempotencia de pagos. Un reintento de red con la misma `referencia`
 * (clave por intento) no debe generar un doble cobro. Índice único parcial sobre
 * (id_orden, referencia) cuando referencia NO es nula: dos pagos de la misma orden no
 * pueden compartir referencia, pero los pagos sin referencia (efectivo simple) no se
 * restringen.
 *
 * Índice parcial = solo PostgreSQL (igual que uq_ordenes_abierta_mesa_parcial del S0).
 * En SQLite la idempotencia recae en la verificación de RegistrarPagoService bajo
 * bloqueo; la paridad de esquema se preserva (no se añaden columnas).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX uq_pagos_orden_referencia_parcial ON pagos (id_orden, referencia) WHERE referencia IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS uq_pagos_orden_referencia_parcial');
        }
        // No-op en SQLite (no se creó índice).
    }
};
