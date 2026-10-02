<?php

namespace App\Http\Middleware;

use App\Models\Establecimiento;
use App\Support\Exceptions\EstablecimientoInactivoException;
use App\Support\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso operativo si el establecimiento del contexto está desactivado (P19 → 423).
 * El SUPER_ADMIN sin contexto pasa; en impersonación de soporte no se bloquea.
 */
class EnsureTenantActivo
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenant->has() || $this->tenant->impersonando()) {
            return $next($request);
        }

        $establecimiento = Establecimiento::find($this->tenant->id());

        if ($establecimiento === null || ! $establecimiento->activo) {
            throw new EstablecimientoInactivoException;
        }

        return $next($request);
    }
}
