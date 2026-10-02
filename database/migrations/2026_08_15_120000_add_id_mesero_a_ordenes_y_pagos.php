<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atribución de la venta a la PERSONA, no a la cuenta, cuando varios meseros comparten una
 * terminal.
 *
 * `id_usuario` (que ya existe) es la cuenta que abrió la orden o registró el pago. Con un
 * dispositivo por mesero eso YA es la persona y basta; con una tablet compartida en la barra,
 * la cuenta es del dispositivo y el reporte "cuánto vendió cada mesero" mentiría.
 *
 * De ahí `id_mesero`: **nullable a propósito**. El establecimiento con dispositivo por mesero
 * no configura nada y lo deja nulo; el de terminal compartida lo llena vía PIN. La regla de
 * resolución es única para ambos escenarios:
 *
 *     mesero_efectivo = COALESCE(id_mesero, id_usuario)
 *
 * Así el filtro "mis órdenes", el traspaso y los reportes leen un solo concepto y no hay dos
 * ramas de lógica que mantener. Ver `App\Domain\Ordenes\MeseroEfectivo`.
 *
 * En `pagos` porque el cobro se firma aparte: quien abre la mesa no siempre es quien cobra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $tabla) {
            $tabla->foreignId('id_mesero')->nullable()->after('id_usuario')->constrained('usuarios');
        });

        Schema::table('pagos', function (Blueprint $tabla) {
            $tabla->foreignId('id_mesero')->nullable()->after('id_usuario')->constrained('usuarios');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $tabla) {
            $tabla->dropConstrainedForeignId('id_mesero');
        });

        Schema::table('pagos', function (Blueprint $tabla) {
            $tabla->dropConstrainedForeignId('id_mesero');
        });
    }
};
