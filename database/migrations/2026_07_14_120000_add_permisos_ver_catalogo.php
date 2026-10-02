<?php

use App\Models\Usuario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Guard;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos de LECTURA del catálogo (`categorias.ver`, `productos.ver`).
 *
 * Hasta ahora leer el catálogo exigía el permiso de GESTIONARLO, que es de admin. El
 * operador quedaba con 403 en `GET /productos` y `GET /categorias`: podía agregar un
 * ítem a la orden pero no ver la rejilla para elegirlo, lo que deja el POS inservible
 * justo para el rol al que está destinado.
 *
 * El seeder ya siembra estos permisos para instalaciones nuevas; esta migración los
 * otorga a los roles que YA existen. Se recorren todos los roles `admin`/`operador` de
 * cualquier team: los roles por establecimiento solo se re-sincronizan con
 * ProveedorRolesTenant cuando se crean, así que sin esto los tenants vivos no se
 * enterarían del permiso nuevo.
 */
return new class extends Migration
{
    private const PERMISOS = ['categorias.ver', 'productos.ver'];

    /** Ambos roles pueden leer el catálogo; solo el admin conserva `*.gestionar`. */
    private const ROLES = ['admin', 'operador'];

    public function up(): void
    {
        $guard = Guard::getDefaultName(Usuario::class);
        $tablas = config('permission.table_names');
        $ahora = now();

        DB::table($tablas['permissions'])->insertOrIgnore(
            array_map(fn (string $permiso) => [
                'name' => $permiso,
                'guard_name' => $guard,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ], self::PERMISOS)
        );

        $idsPermisos = DB::table($tablas['permissions'])
            ->whereIn('name', self::PERMISOS)
            ->where('guard_name', $guard)
            ->pluck('id');

        $idsRoles = DB::table($tablas['roles'])
            ->whereIn('name', self::ROLES)
            ->where('guard_name', $guard)
            ->pluck('id');

        $filas = [];

        foreach ($idsRoles as $idRol) {
            foreach ($idsPermisos as $idPermiso) {
                $filas[] = ['role_id' => $idRol, 'permission_id' => $idPermiso];
            }
        }

        if ($filas !== []) {
            DB::table($tablas['role_has_permissions'])->insertOrIgnore($filas);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = Guard::getDefaultName(Usuario::class);
        $tablas = config('permission.table_names');

        $idsPermisos = DB::table($tablas['permissions'])
            ->whereIn('name', self::PERMISOS)
            ->where('guard_name', $guard)
            ->pluck('id');

        DB::table($tablas['role_has_permissions'])->whereIn('permission_id', $idsPermisos)->delete();
        DB::table($tablas['permissions'])->whereIn('id', $idsPermisos)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
