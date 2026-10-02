<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DER V1.2 · Tabla 4: usuarios.
 * id_establecimiento NULL = super_admin (no pertenece a un establecimiento).
 * id_rol es cache del rol principal; la verdad de roles vive en las tablas de Spatie.
 * Unicidad de email/username POR establecimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_establecimiento')->nullable()->constrained('establecimientos');
            $table->foreignId('id_rol')->nullable()->constrained('roles');
            $table->string('nombre', 100);
            $table->string('email', 150)->nullable();
            $table->string('username', 50)->nullable();
            $table->string('password_hash', 255);
            $table->boolean('activo')->default(true);
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id_establecimiento', 'email'], 'uq_usuarios_establecimiento_email');
            $table->unique(['id_establecimiento', 'username'], 'uq_usuarios_establecimiento_username');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
