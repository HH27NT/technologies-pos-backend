<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orquesta la siembra base del Sprint 0 en orden:
 * 1) catálogos globales, 2) roles/permisos, 3) super_admin inicial.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CatalogosGlobalesSeeder::class,
            RolesPermisosSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
