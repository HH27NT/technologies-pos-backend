<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\Exportacion\FabricaExporter;
use App\Domain\Reportes\Queries\ReporteQuery;
use App\Domain\Reportes\RangoFechas;
use App\Domain\Reportes\Reporte;
use App\Domain\Reportes\TipoReporte;
use App\Http\Requests\ExportarReporteRequest;
use App\Http\Requests\RangoReporteRequest;
use App\Jobs\GenerarReporteExportJob;
use App\Models\Establecimiento;
use App\Support\Http\ApiResponse;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * M16 · Reportes de solo lectura (ReportePolicy). Los reportes operativos los ven ambos
 * roles con alcance distinto (P21); los de gestión (inventario/cancelaciones/margen)
 * solo el ADMIN. Los rangos se resuelven en la zona horaria del establecimiento. La
 * exportación PDF/Excel se encola (nunca bloquea la respuesta). El aislamiento por tenant
 * lo garantiza el TenantScope de cada fuente.
 */
class ReporteController extends ApiController
{
    public function dashboard(RangoReporteRequest $request): JsonResponse
    {
        return $this->reporte(TipoReporte::Dashboard, $request->validated());
    }

    public function ventas(RangoReporteRequest $request): JsonResponse
    {
        return $this->reporte(TipoReporte::Ventas, $request->validated());
    }

    public function ventasMensuales(RangoReporteRequest $request): JsonResponse
    {
        return $this->reporte(TipoReporte::VentasMensuales, $request->validated());
    }

    public function consumoInsumos(RangoReporteRequest $request): JsonResponse
    {
        return $this->reporte(TipoReporte::ConsumoInsumos, $request->validated());
    }

    public function topRecetas(RangoReporteRequest $request): JsonResponse
    {
        return $this->reporte(TipoReporte::TopRecetas, $request->validated());
    }

    public function caja(RangoReporteRequest $request): JsonResponse
    {
        return $this->reporte(TipoReporte::Caja, $request->validated());
    }

    public function mediosPago(RangoReporteRequest $request): JsonResponse
    {
        return $this->reporte(TipoReporte::MediosPago, $request->validated());
    }

    /**
     * Cuánto cobró cada mesero. Agrupa por mesero **efectivo**, así que sirve igual con
     * dispositivo por mesero que con terminal compartida. Es de gestión (solo admin/gerente):
     * comparar el desempeño entre compañeros no es información de turno.
     */
    public function ventasPorMesero(RangoReporteRequest $request): JsonResponse
    {
        return $this->reporte(TipoReporte::VentasPorMesero, $request->validated());
    }

    public function inventario(RangoReporteRequest $request): JsonResponse
    {
        return $this->reporte(TipoReporte::Inventario, $request->validated());
    }

    public function cancelaciones(RangoReporteRequest $request): JsonResponse
    {
        return $this->reporte(TipoReporte::Cancelaciones, $request->validated());
    }

    public function margen(RangoReporteRequest $request): JsonResponse
    {
        return $this->reporte(TipoReporte::Margen, $request->validated());
    }

    /** Encola la generación del archivo (PDF/Excel) y responde 202 con la URL de descarga. */
    public function exportar(ExportarReporteRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $tipo = TipoReporte::from($datos['reporte']);

        $this->autorizar($tipo);
        $this->authorize('exportar', Reporte::class);

        $exporter = app(FabricaExporter::class)->para($datos['formato']);
        $idTenant = app(TenantContext::class)->id();
        $archivo = Str::uuid()->toString().'.'.$exporter->extension();
        $ruta = "exportaciones/{$idTenant}/{$archivo}";

        GenerarReporteExportJob::dispatch(
            idEstablecimiento: $idTenant,
            reporte: $tipo->value,
            formato: $datos['formato'],
            rangoInput: $datos,
            zonaHoraria: $this->zonaHoraria(),
            alcanceSnapshot: AlcanceReporte::para($request->user())->snapshot(),
            ruta: $ruta,
        );

        return ApiResponse::exito([
            'id_export' => $archivo,
            'reporte' => $tipo->value,
            'formato' => $datos['formato'],
            'descarga_url' => url("/api/v1/reportes/exportaciones/{$archivo}"),
        ], 'La exportación se está generando.', 202);
    }

    /** Descarga el archivo exportado, acotado a la carpeta del tenant (sin path traversal). */
    public function descargar(string $archivo): StreamedResponse
    {
        $this->authorize('exportar', Reporte::class);

        $idTenant = app(TenantContext::class)->id();
        $ruta = "exportaciones/{$idTenant}/".basename($archivo);

        abort_unless(Storage::disk('local')->exists($ruta), 404, 'Exportación no encontrada.');

        return Storage::disk('local')->download($ruta);
    }

    /** Resuelve rango + alcance, ejecuta el Query Service y devuelve el payload. */
    private function reporte(TipoReporte $tipo, array $input): JsonResponse
    {
        $this->autorizar($tipo);

        $rango = RangoFechas::desde($input, $this->zonaHoraria());
        $alcance = AlcanceReporte::para(request()->user());

        /** @var ReporteQuery $query */
        $query = app($tipo->queryClass());

        return ApiResponse::exito($query->ejecutar($rango, $alcance));
    }

    /**
     * El dashboard exige `verDashboard` (exclusivo del dueño, ni el GERENTE); los
     * reportes de gestión exigen `verGestion` (solo ADMIN, P21); el resto, `ver`.
     */
    private function autorizar(TipoReporte $tipo): void
    {
        $accion = match (true) {
            $tipo === TipoReporte::Dashboard => 'verDashboard',
            $tipo->soloAdmin() => 'verGestion',
            default => 'ver',
        };

        $this->authorize($accion, Reporte::class);
    }

    private function zonaHoraria(): ?string
    {
        return Establecimiento::find(app(TenantContext::class)->id())?->zona_horaria;
    }
}
