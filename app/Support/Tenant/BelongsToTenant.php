<?php

namespace App\Support\Tenant;

use App\Models\Establecimiento;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait para entidades operativas multi-tenant.
 *
 * - Aplica el TenantScope (aislamiento automático por id_establecimiento).
 * - Autollena id_establecimiento al crear con el tenant del contexto.
 *
 * La clave foránea de tenant es `id_establecimiento` (convención del DER, no el
 * default de Laravel `establecimiento_id`).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($context->has() && empty($model->getAttribute($model->getTenantColumn()))) {
                $model->setAttribute($model->getTenantColumn(), $context->id());
            }
        });
    }

    /** Nombre de la columna discriminadora de tenant. */
    public function getTenantColumn(): string
    {
        return 'id_establecimiento';
    }

    /** Columna de tenant calificada con la tabla, para usar en WHERE de joins. */
    public function getQualifiedTenantColumn(): string
    {
        return $this->getTable().'.'.$this->getTenantColumn();
    }

    /** Relación al establecimiento dueño. */
    public function establecimiento()
    {
        return $this->belongsTo(Establecimiento::class, 'id_establecimiento');
    }
}
