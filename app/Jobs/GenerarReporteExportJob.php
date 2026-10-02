<?php

namespace App\Jobs;

use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\Exportacion\FabricaExporter;
use App\Domain\Reportes\Queries\ReporteQuery;
use App\Domain\Reportes\RangoFechas;
use App\Domain\Reportes\TipoReporte;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * M16 · Genera el archivo de exportación (PDF/Excel) de un reporte FUERA de la respuesta
 * (cola `reportes`, con reintentos): nunca bloquea la petición interactiva. Re-ejecuta el
 * Query Service de solo lectura con el MISMO alcance por rol (P21) y escribe el archivo
 * en `storage` particionado por tenant.
 *
 * El contexto de tenant se re-fija aquí (el worker no hereda el de la request): por eso
 * el job viaja con `idEstablecimiento` y un snapshot serializable del alcance, sin
 * depender del usuario autenticado dentro del worker.
 */
class GenerarReporteExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public readonly int $idEstablecimiento,
        public readonly string $reporte,
        public readonly string $formato,
        public readonly array $rangoInput,
        public readonly ?string $zonaHoraria,
        public readonly array $alcanceSnapshot,
        public readonly string $ruta,
    ) {
        $this->onQueue('reportes');
    }

    public function handle(TenantContext $tenant, FabricaExporter $fabrica): void
    {
        // Re-fija el tenant en el worker para que los Query Services filtren por scope.
        $tenant->set($this->idEstablecimiento);

        $rango = RangoFechas::desde($this->rangoInput, $this->zonaHoraria);
        $alcance = AlcanceReporte::desdeSnapshot($this->alcanceSnapshot);

        /** @var ReporteQuery $query */
        $query = app(TipoReporte::from($this->reporte)->queryClass());
        $payload = $query->ejecutar($rango, $alcance);

        $exporter = $fabrica->para($this->formato);
        Storage::disk('local')->put($this->ruta, $exporter->exportar($payload));
    }
}
