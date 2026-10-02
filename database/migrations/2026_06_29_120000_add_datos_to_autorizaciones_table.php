<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 9 (D1) · Payload de la operación solicitada.
 *
 * La tabla `autorizaciones` solo guardaba `motivo`; una solicitud de entrada/ajuste de
 * stock necesita además id_insumo/cantidad/costo_unitario, que no existen como movimiento
 * mientras la solicitud está `pendiente` (el movimiento se crea al aprobar). Esta columna
 * JSONB nullable conserva esos parámetros hasta la resolución. Portable (pgsql jsonb /
 * sqlite text); no altera la forma del resto de la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autorizaciones', function (Blueprint $table) {
            $table->json('datos')->nullable()->after('motivo');
        });
    }

    public function down(): void
    {
        Schema::table('autorizaciones', function (Blueprint $table) {
            $table->dropColumn('datos');
        });
    }
};
