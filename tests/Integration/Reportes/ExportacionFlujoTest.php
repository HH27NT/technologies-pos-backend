<?php

namespace Tests\Integration\Reportes;

use App\Domain\Pagos\Services\RegistrarPagoService;
use App\Models\Establecimiento;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M16 · La exportación se ENCOLA (GenerarReporteExportJob, cola `reportes`): el job
 * re-ejecuta el Query Service con el alcance del rol y escribe el archivo por tenant en
 * `storage`, sin bloquear la respuesta (202). El operador no exporta reportes de gestión.
 */
class ExportacionFlujoTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('expf');
        $this->operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');

        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);

        $orden = $this->ordenAbiertaConTotal(100);
        $efectivo = TipoPago::where('nombre', 'efectivo')->value('id');
        app(RegistrarPagoService::class)->registrar($orden, ['id_tipo_pago' => $efectivo, 'monto' => 100]);
    }

    public function test_exporta_pdf_y_genera_el_archivo(): void
    {
        $resp = $this->postJson('/api/v1/reportes/exportar', [
            'reporte' => 'ventas', 'formato' => 'pdf', 'preset' => 'hoy',
        ])->assertStatus(202);

        $archivo = $resp->json('data.id_export');
        $this->assertStringEndsWith('.pdf', $archivo);
        Storage::disk('local')->assertExists("exportaciones/{$this->establecimiento->id}/{$archivo}");

        // La descarga entrega el archivo generado.
        $this->get("/api/v1/reportes/exportaciones/{$archivo}")->assertOk();
    }

    public function test_exporta_excel_y_genera_el_archivo(): void
    {
        $resp = $this->postJson('/api/v1/reportes/exportar', [
            'reporte' => 'medios-pago', 'formato' => 'excel', 'preset' => 'hoy',
        ])->assertStatus(202);

        $archivo = $resp->json('data.id_export');
        $this->assertStringEndsWith('.xlsx', $archivo);
        Storage::disk('local')->assertExists("exportaciones/{$this->establecimiento->id}/{$archivo}");
    }

    public function test_operador_no_exporta_reporte_de_gestion(): void
    {
        Sanctum::actingAs($this->operador);

        $this->postJson('/api/v1/reportes/exportar', [
            'reporte' => 'margen', 'formato' => 'pdf', 'preset' => 'hoy',
        ])->assertForbidden();
    }

    public function test_reporte_invalido_devuelve_422(): void
    {
        $this->postJson('/api/v1/reportes/exportar', [
            'reporte' => 'inexistente', 'formato' => 'pdf',
        ])->assertStatus(422);
    }
}
