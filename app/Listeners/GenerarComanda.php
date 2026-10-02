<?php

namespace App\Listeners;

use App\Domain\Impresion\Services\GenerarComandaService;
use App\Domain\Ordenes\Events\ItemConfirmado;
use App\Models\ConfiguracionEstablecimiento;

/**
 * M13 · Al confirmar la comanda (S7), genera y encola la comanda física SI el
 * establecimiento tiene impresión automática activada (D3). Síncrono y AUTO-DESCUBIERTO
 * por su método handle(ItemConfirmado): NO registrarlo en AppServiceProvider (se
 * duplicaría, igual que DescontarInventario en S8). Sin la bandera, la comanda se
 * imprime por el flujo manual del cliente.
 */
class GenerarComanda
{
    public function __construct(private readonly GenerarComandaService $servicio) {}

    public function handle(ItemConfirmado $evento): void
    {
        $orden = $evento->orden;

        $automatica = ConfiguracionEstablecimiento::where('id_establecimiento', $orden->id_establecimiento)
            ->value('impresion_automatica');

        if ($automatica) {
            $this->servicio->generar($orden);
        }
    }
}
