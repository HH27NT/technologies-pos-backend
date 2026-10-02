<?php

namespace App\Support\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope que aísla por tenant: añade WHERE id_establecimiento = TenantContext::id().
 *
 * Si no hay contexto fijado (super_admin sin impersonación, consola, seeders) no aplica
 * filtro alguno, de modo que esos flujos ven todos los registros de forma deliberada (§7).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->has()) {
            /** @var BelongsToTenant $model */
            $builder->where($model->getQualifiedTenantColumn(), $context->id());
        }
    }
}
