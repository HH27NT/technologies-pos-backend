<?php

namespace App\Domain\Usuarios\Services;

use App\Domain\Usuarios\CatalogoPermisos;
use App\Domain\Usuarios\Events\RolModificado;
use App\Models\Rol;
use App\Models\Usuario;
use App\Support\Exceptions\RolEnUsoException;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Alta, edición y baja de roles A MEDIDA del establecimiento (M04 · editor de roles).
 *
 * Los roles del sistema no pasan por aquí para editarse (RolPolicy lo impide); sí pueden
 * usarse como ORIGEN de una clonación, que es la vía prevista para personalizar.
 */
class GuardarRolService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionRegistrar $permisos,
    ) {}

    /** @param array{etiqueta: string, descripcion?: ?string, permisos: string[]} $datos */
    public function crear(array $datos): Rol
    {
        return DB::transaction(function () use ($datos) {
            $rol = new Rol([
                'name' => $this->nombreTecnicoUnico($datos['etiqueta']),
                'etiqueta' => $datos['etiqueta'],
                'descripcion' => $datos['descripcion'] ?? null,
                'guard_name' => Guard::getDefaultName(Usuario::class),
            ]);
            // BelongsToTenant lo autollena, pero se fija explícito porque un rol sin
            // establecimiento sería un rol PLANTILLA global (visible para todos).
            $rol->id_establecimiento = (int) $this->tenant->id();
            $rol->save();

            $this->sincronizarPermisos($rol, $datos['permisos']);

            event(new RolModificado(
                $rol,
                datosAntes: [],
                datosDespues: $this->instantanea($rol),
                accion: 'rol.creado',
            ));

            return $rol;
        });
    }

    /**
     * Clona un rol existente (típicamente un preset) como rol propio del tenant.
     *
     * Es la vía de personalización: los presets son de solo lectura porque
     * `roles:sincronizar` los reescribe en cada arranque, así que editarlos daría la
     * ilusión de funcionar hasta el siguiente despliegue.
     *
     * @param  string[]|null  $permisos  Permisos a fijar; si es null, hereda los del origen.
     */
    public function clonar(Rol $origen, string $etiqueta, ?string $descripcion = null, ?array $permisos = null): Rol
    {
        return $this->crear([
            'etiqueta' => $etiqueta,
            'descripcion' => $descripcion,
            'permisos' => $permisos ?? $origen->permissions->pluck('name')->all(),
        ]);
    }

    /** @param array{etiqueta?: string, descripcion?: ?string, permisos?: string[]} $datos */
    public function actualizar(Rol $rol, array $datos): Rol
    {
        return DB::transaction(function () use ($rol, $datos) {
            $antes = $this->instantanea($rol);

            // El nombre técnico NO se regenera al renombrar: es el identificador estable
            // de Spatie y ya está grabado en model_has_roles. Solo cambia la etiqueta.
            $rol->fill(array_intersect_key($datos, array_flip(['etiqueta', 'descripcion'])));
            $rol->save();

            if (array_key_exists('permisos', $datos)) {
                $this->sincronizarPermisos($rol, $datos['permisos']);
            }

            event(new RolModificado($rol, $antes, $this->instantanea($rol->fresh('permissions')), 'rol.modificado'));

            return $rol;
        });
    }

    public function eliminar(Rol $rol): void
    {
        DB::transaction(function () use ($rol) {
            if ($rol->usuarios()->exists()) {
                throw new RolEnUsoException;
            }

            $antes = $this->instantanea($rol);

            $rol->delete();

            event(new RolModificado($rol, $antes, [], 'rol.eliminado'));
        });
    }

    /**
     * Deriva un `name` técnico estable y único dentro del tenant.
     *
     * Spatie identifica los roles por `name`, y el unique de la tabla es
     * (id_establecimiento, name, guard_name). Se sufija con un contador ante colisión
     * en vez de fallar: la etiqueta duplicada ya la rechaza el Form Request, y aquí
     * solo se resuelven choques de slug ("Cajero A" y "Cajero Á" colapsan al mismo).
     */
    private function nombreTecnicoUnico(string $etiqueta): string
    {
        $base = Str::slug($etiqueta, '_') ?: 'rol';
        $base = Str::limit($base, 40, '');

        $nombre = $base;
        $sufijo = 2;

        while ($this->nombreExiste($nombre)) {
            $nombre = $base.'_'.$sufijo;
            $sufijo++;
        }

        return $nombre;
    }

    private function nombreExiste(string $nombre): bool
    {
        return Rol::withoutGlobalScopes()
            ->where('id_establecimiento', $this->tenant->id())
            ->where('name', $nombre)
            ->exists();
    }

    /**
     * Fija los permisos del rol resolviéndolos con guard explícito y filtrando a los
     * asignables. El Form Request ya validó, pero el servicio no confía en su llamador:
     * es la última línea antes de escribir privilegios en la base.
     *
     * @param  string[]  $permisos
     */
    private function sincronizarPermisos(Rol $rol, array $permisos): void
    {
        $guard = Guard::getDefaultName(Usuario::class);

        $modelos = Permission::whereIn('name', array_intersect($permisos, CatalogoPermisos::asignables()))
            ->where('guard_name', $guard)
            ->get();

        // syncPermissions escribe en role_has_permissions, que no depende del team activo,
        // pero el registrar cachea por team: se limpia para que el cambio se vea al instante.
        $rol->syncPermissions($modelos);
        $this->permisos->forgetCachedPermissions();
    }

    /** @return array<string, mixed> */
    private function instantanea(Rol $rol): array
    {
        return [
            'name' => $rol->name,
            'etiqueta' => $rol->etiqueta,
            'descripcion' => $rol->descripcion,
            'permisos' => $rol->permissions->pluck('name')->sort()->values()->all(),
        ];
    }
}
