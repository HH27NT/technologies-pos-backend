<?php

use App\Http\Middleware\EnsureCajaAbierta;
use App\Http\Middleware\EnsureTenantActivo;
use App\Http\Middleware\ResolveTenant;
use App\Support\Exceptions\DemasiadosIntentosException;
use App\Support\Exceptions\DomainException;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
            'tenant.activo' => EnsureTenantActivo::class,
            // Compuerta de venta (M10). Se aplica a las rutas de órdenes en el Sprint 7.
            'caja.abierta' => EnsureCajaAbierta::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Traduce las excepciones a la envoltura JSON estándar (Convenciones §4.3, §20).
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                // Antes del caso general: el 429 del override por PIN lleva Retry-After para
                // que el front sepa cuándo puede reintentar (M14.1).
                $e instanceof DemasiadosIntentosException => ApiResponse::error($e->getMessage(), [], 429)
                    ->header('Retry-After', (string) $e->segundos()),
                $e instanceof DomainException => ApiResponse::error($e->getMessage(), [], $e->statusHttp()),
                $e instanceof ValidationException => ApiResponse::error(
                    collect($e->errors())->flatten()->first() ?? 'Datos inválidos.',
                    $e->errors(),
                    422,
                ),
                $e instanceof AuthenticationException => ApiResponse::error('No autenticado.', [], 401),
                $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiResponse::error('No tienes permiso para realizar esta acción.', [], 403),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error('Recurso no encontrado.', [], 404),
                default => null,
            };
        });
    })->create();
