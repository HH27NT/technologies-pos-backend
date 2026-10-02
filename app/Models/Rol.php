<?php

namespace App\Models;

use App\Domain\Usuarios\CatalogoRoles;
use App\Support\Tenant\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role;

/**
 * Rol de un ESTABLECIMIENTO, visto desde la administración (editor de roles).
 *
 * Extiende el Role de Spatie en lugar de reemplazarlo en `config/permission.php`: Spatie
 * sigue usando su propio modelo internamente (hasRole, assignRole, el registrar de
 * permisos), y este se usa SOLO en el CRUD de roles. La razón es no meter un scope global
 * en el camino de la resolución de permisos, donde un filtro de más se convierte en un
 * 403 fantasma difícil de rastrear.
 *
 * Lo que aporta sobre el de Spatie:
 *  - `BelongsToTenant` → aislamiento automático (un id de otro tenant da 404, no 403) y
 *    autollenado de `id_establecimiento` al crear, la convención del resto del DER.
 *  - La distinción sistema/propio, que es la regla central del editor.
 */
class Rol extends Role
{
    use BelongsToTenant;

    protected $fillable = ['name', 'etiqueta', 'descripcion', 'guard_name', 'id_establecimiento'];

    /**
     * ¿Es uno de los roles preset del catálogo (admin/gerente/operador/mesero)?
     *
     * Se deriva del nombre y NO de una columna `es_sistema`: el catálogo ya es la fuente
     * de verdad de qué roles son del sistema, y una columna sería una segunda copia que
     * puede quedar desincronizada — el bug exacto que este módulo acaba de corregir.
     */
    public function esDelSistema(): bool
    {
        return in_array($this->name, CatalogoRoles::nombresTenant(), true);
    }

    /** Nombre a mostrar: la etiqueta propia si la tiene, si no el nombre técnico. */
    public function etiquetaLegible(): string
    {
        return $this->etiqueta ?: $this->name;
    }

    /**
     * Usuarios que tienen este rol, por el cache `usuarios.id_rol`.
     *
     * Se usa para el conteo del editor y para impedir borrar un rol en uso. Va por
     * `id_rol` (y no por `model_has_roles`) porque es la columna con FK: es la que
     * realmente bloquearía el DELETE en la base.
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'id_rol');
    }
}
