<?php

namespace App\Http\Middleware;

use App\Models\SesionCaja;
use App\Support\Exceptions\SinCajaAbiertaException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M10 · Compuerta de la venta (regla global 5): exige una caja `abierta` en el
 * establecimiento antes de operar. Corre tras resolve.tenant (necesita el contexto).
 *
 * Se construye y prueba en el Sprint 6; su aplicación a las rutas de venta llega en
 * el Sprint 7 (Órdenes), cuando existan rutas que proteger.
 */
class EnsureCajaAbierta
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! SesionCaja::where('estado', 'abierta')->exists()) {
            throw new SinCajaAbiertaException;
        }

        return $next($request);
    }
}
