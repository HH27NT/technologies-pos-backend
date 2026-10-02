<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre legible y descripción de los roles, para el EDITOR DE ROLES A MEDIDA.
 *
 * `roles.name` es el identificador técnico de Spatie (se consulta con `hasRole('admin')`,
 * así que debe ser estable y sin espacios). Un rol a medida necesita además un nombre que
 * el dueño del bar pueda leer y CAMBIAR sin romper las asignaciones existentes: eso es
 * `etiqueta`. Los roles del sistema la dejan nula y siguen etiquetándose desde el catálogo.
 *
 * Nullable a propósito: no hay que rellenar los roles ya existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $tabla) {
            $tabla->string('etiqueta', 60)->nullable()->after('name');
            $tabla->string('descripcion', 200)->nullable()->after('etiqueta');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $tabla) {
            $tabla->dropColumn(['etiqueta', 'descripcion']);
        });
    }
};
