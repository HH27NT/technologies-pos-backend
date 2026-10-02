<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 (D1) · Motivo del arqueo cuando hay diferencia al cerrar caja.
 * El DER exigía "motivo si diferencia ≠ 0" como regla de negocio, pero la tabla
 * sesiones_caja no tenía dónde guardarlo. Columna portable (string nullable, sin
 * índices) ⇒ paridad SQLite↔PostgreSQL sin cambios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones_caja', function (Blueprint $table) {
            $table->string('motivo', 500)->nullable()->after('diferencia');
        });
    }

    public function down(): void
    {
        Schema::table('sesiones_caja', function (Blueprint $table) {
            $table->dropColumn('motivo');
        });
    }
};
