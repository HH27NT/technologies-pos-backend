<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14 · Override de autorización por contraseña de admin.
 *
 * Distingue el ORIGEN de una autorización aprobada: `asincrono` (solicitada por el
 * operador y aprobada desde la bandeja del admin, flujo de dos niveles S9) vs `override`
 * (el operador teclea la contraseña de un admin y la operación se ejecuta al instante).
 * Las filas existentes y toda solicitud del flujo asíncrono conservan el default
 * `asincrono`. Portable pgsql/sqlite (enum → varchar + check).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autorizaciones', function (Blueprint $table) {
            $table->enum('metodo', ['asincrono', 'override'])->default('asincrono')->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('autorizaciones', function (Blueprint $table) {
            $table->dropColumn('metodo');
        });
    }
};
