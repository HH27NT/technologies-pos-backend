<?php

namespace App\Listeners;

use App\Domain\Impresion\Services\GenerarTicketService;
use App\Domain\Pagos\Events\OrdenPagada;
use App\Models\ConfiguracionEstablecimiento;

/**
 * M13 · Al saldar la orden (S8), genera y encola el ticket de cobro SI el establecimiento
 * tiene impresión automática activada (D3). Síncrono y AUTO-DESCUBIERTO por su método
 * handle(OrdenPagada): NO registrarlo en AppServiceProvider (se duplicaría). La impresión
 * NUNCA bloquea el cobro: persiste el ticket y ENCOLA el envío (afterCommit). Sin la
 * bandera, el cajero imprime con POST /ordenes/{id}/ticket.
 */
class ImprimirTicket
{
    public function __construct(private readonly GenerarTicketService $servicio) {}

    public function handle(OrdenPagada $evento): void
    {
        $orden = $evento->orden;

        $automatica = ConfiguracionEstablecimiento::where('id_establecimiento', $orden->id_establecimiento)
            ->value('impresion_automatica');

        if ($automatica) {
            $this->servicio->generar($orden);
        }
    }
}
