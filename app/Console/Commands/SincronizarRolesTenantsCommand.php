<?php

namespace App\Console\Commands;

use App\Domain\Usuarios\CatalogoRoles;
use App\Domain\Usuarios\Services\ProveedorRolesTenant;
use App\Models\Establecimiento;
use Illuminate\Console\Command;

/**
 * Resincroniza los roles por-tenant contra la matriz de CatalogoRoles.
 *
 * Los roles Spatie se materializan por establecimiento (teams) y sus permisos se
 * congelan al crearlos (ProveedorRolesTenant::obtener). Por eso, cambiar la matriz
 * NO se propaga solo a los tenants YA existentes: hay que resincronizar.
 *
 * Este comando recorre todos los establecimientos y vuelve a sincronizar cada rol
 * del catálogo (crea el que falte, ajusta permisos del que exista). Es idempotente,
 * y por eso el entrypoint del contenedor lo corre en cada arranque tras el seed: así
 * un rol o permiso nuevo alcanza a los tenants vivos sin intervención manual.
 */
class SincronizarRolesTenantsCommand extends Command
{
    // El default NO se escribe aquí (la firma solo admite literales): si se omite la
    // opción se usa el catálogo completo, resuelto en handle().
    protected $signature = 'roles:sincronizar {--roles= : Roles a resincronizar, separados por coma (por defecto, todo el catálogo)}';

    protected $description = 'Resincroniza los roles por-tenant con la matriz del seeder (idempotente).';

    public function handle(ProveedorRolesTenant $proveedor): int
    {
        $roles = collect(explode(',', (string) $this->option('roles')))
            ->map(fn ($r) => trim($r))
            ->filter()
            ->values();

        if ($roles->isEmpty()) {
            $roles = collect(CatalogoRoles::nombresTenant());
        }

        $establecimientos = Establecimiento::query()->orderBy('id')->get(['id', 'nombre']);

        if ($establecimientos->isEmpty()) {
            $this->info('No hay establecimientos que resincronizar.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Resincronizando %d establecimiento(s) · roles: %s',
            $establecimientos->count(), $roles->implode(', ')));

        foreach ($establecimientos as $establecimiento) {
            foreach ($roles as $rol) {
                $proveedor->obtener($rol, $establecimiento->id);
            }
            $this->line(sprintf('  ✓ #%d %s', $establecimiento->id, $establecimiento->nombre));
        }

        $this->info('Roles resincronizados.');

        return self::SUCCESS;
    }
}
