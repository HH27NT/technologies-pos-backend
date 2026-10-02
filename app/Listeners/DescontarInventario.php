<?php

namespace App\Listeners;

use App\Domain\Inventario\Services\DescontarInventarioService;
use App\Domain\Pagos\Events\OrdenPagada;

/**
 * Listener SÍNCRONO del descuento de inventario al cobrar (P1). Reacciona a OrdenPagada
 * y corre DENTRO de la transacción del cobro (no implementa ShouldQueue a propósito):
 * si el cobro se revierte, el descuento también. Registrado en AppServiceProvider.
 */
class DescontarInventario
{
    public function __construct(private readonly DescontarInventarioService $descuento) {}

    public function handle(OrdenPagada $evento): void
    {
        $this->descuento->descontar($evento->orden);
    }
}
