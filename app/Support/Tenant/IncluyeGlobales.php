<?php

namespace App\Support\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Scope híbrido para unidades_medida (P4): las consultas devuelven las filas del
 * tenant actual MÁS las predefinidas globales (id_establecimiento NULL).
 *
 * Las globales son de solo lectura para el establecimiento (esa regla la imponen
 * la policy y los servicios en sprints posteriores; aquí solo se gobierna la lectura).
 */
trait IncluyeGlobales
{
    public static function bootIncluyeGlobales(): void
    {
        static::addGlobalScope('incluye_globales', function (Builder $builder): void {
            $context = app(TenantContext::class);

            if ($context->has()) {
                $column = $builder->getModel()->getQualifiedTenantColumn();

                $builder->where(function (Builder $q) use ($column, $context): void {
                    $q->where($column, $context->id())->orWhereNull($column);
                });
            }
        });

        static::creating(function (Model $model): void {
            // Unidad propia: se crea con el tenant actual. Las globales (seed) se insertan
            // con id_establecimiento nulo explícito, por lo que no se sobreescriben aquí.
            $context = app(TenantContext::class);
            $column = $model->getTenantColumn();

            if ($context->has() && ! array_key_exists($column, $model->getAttributes())) {
                $model->setAttribute($column, $context->id());
            }
        });
    }

    public function getTenantColumn(): string
    {
        return 'id_establecimiento';
    }

    public function getQualifiedTenantColumn(): string
    {
        return $this->getTable().'.'.$this->getTenantColumn();
    }
}
