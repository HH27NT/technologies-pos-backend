<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Super administrador inicial de la plataforma (tenant NULL), idempotente.
 * Credenciales por variables de entorno; cámbialas tras el primer arranque.
 *
 * El super_admin es un rol GLOBAL: su autorización la concede `Gate::before`
 * (Sprint 1), no una asignación en model_has_roles (cuyo team es por-tenant).
 * Por eso aquí NO se hace assignRole; solo se guarda id_rol como cache del rol
 * principal (reconciliación DER ↔ Spatie, §8) y se identifica por tenant nulo.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $rolSuperAdmin = Role::where('name', 'super_admin')->where('guard_name', $guard)->first();

        Usuario::firstOrCreate(
            ['email' => env('SUPER_ADMIN_EMAIL', 'super@pos.local'), 'id_establecimiento' => null],
            [
                'nombre' => 'Super Administrador',
                'username' => env('SUPER_ADMIN_USERNAME', 'superadmin'),
                'password_hash' => env('SUPER_ADMIN_PASSWORD', 'password'),
                'activo' => true,
                'id_rol' => $rolSuperAdmin?->id,
            ]
        );
    }
}
