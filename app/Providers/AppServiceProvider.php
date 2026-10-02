<?php

namespace App\Providers;

use App\Domain\Reportes\Reporte;
use App\Listeners\RegistrarAuditoria;
use App\Models\Usuario;
use App\Policies\ReportePolicy;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Contexto de tenant compartido durante toda la petición (Sprint 0).
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // Las respuestas usan la envoltura propia de ApiResponse, sin el "data" de JsonResource.
        JsonResource::withoutWrapping();

        // El SUPER_ADMIN pasa todas las autorizaciones (Arq §8/§9).
        Gate::before(fn ($usuario, string $ability) => $usuario instanceof Usuario && $usuario->esSuperAdmin() ? true : null);

        // M16 · Los reportes no tienen modelo Eloquent: su policy se registra contra el
        // marcador App\Domain\Reportes\Reporte (las demás policies se auto-descubren por
        // convención Model↔Policy, incl. AuditoriaPolicy sobre el modelo Auditoria).
        Gate::policy(Reporte::class, ReportePolicy::class);

        // Auditoría síncrona de los hechos de negocio (§13/§15). Subscriber con métodos
        // con nombre propio (no `handle`), por eso se registra explícitamente.
        Event::subscribe(RegistrarAuditoria::class);

        // El listener App\Listeners\DescontarInventario (descuento de inventario al
        // cobrar, P1) se auto-descubre por su método handle(OrdenPagada): NO debe
        // registrarse aquí también (se duplicaría el descuento).
    }
}
