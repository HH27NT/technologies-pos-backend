<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interruptor por tenant del modo "terminal compartida".
 *
 * Default `false`: el establecimiento donde cada mesero trae su tablet no ve nada nuevo ni
 * paga complejidad por una función que no usa. Al activarlo, el POS pide PIN para atribuir la
 * venta a la persona y la terminal se bloquea sola.
 *
 * `bloqueo_terminal_segundos` es el auto-bloqueo por inactividad. 120 s por defecto: agresivo,
 * pero una tablet desatendida en la barra es justo el riesgo que este modo introduce. Se hace
 * configurable porque la tolerancia real depende del local (una cantina con cuatro mesas no
 * opera como un salón de sesenta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_establecimiento', function (Blueprint $tabla) {
            $tabla->boolean('terminal_compartida')->default(false)->after('impresion_automatica');
            $tabla->unsignedSmallInteger('bloqueo_terminal_segundos')->default(120)->after('terminal_compartida');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_establecimiento', function (Blueprint $tabla) {
            $tabla->dropColumn(['terminal_compartida', 'bloqueo_terminal_segundos']);
        });
    }
};
