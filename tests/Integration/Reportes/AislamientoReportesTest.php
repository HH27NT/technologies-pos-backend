<?php

namespace Tests\Integration\Reportes;

use App\Domain\Pagos\Services\RegistrarPagoService;
use App\Models\Establecimiento;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M16 · Aislamiento por tenant de la capa de lectura: un reporte del tenant A nunca
 * incluye datos de B; la auditoría del ADMIN es solo la de su establecimiento. El único
 * cruce autorizado es la auditoría global del super_admin.
 */
class AislamientoReportesTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $tenantA;

    private Usuario $adminA;

    private Usuario $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->tenantA, 'admin' => $this->adminA] = $this->nuevoTenant('aisA');
        ['admin' => $this->adminB] = $this->nuevoTenant('aisB');

        // Venta (y auditoría) en el tenant A.
        $this->enContextoDe($this->tenantA->id);
        Sanctum::actingAs($this->adminA);
        $this->abrirCaja($this->adminA);
        $orden = $this->ordenAbiertaConTotal(100);
        $efectivo = TipoPago::where('nombre', 'efectivo')->value('id');
        app(RegistrarPagoService::class)->registrar($orden, ['id_tipo_pago' => $efectivo, 'monto' => 100]);
    }

    public function test_el_reporte_no_incluye_datos_de_otro_tenant(): void
    {
        // El admin de A ve su venta.
        Sanctum::actingAs($this->adminA);
        $this->getJson('/api/v1/reportes/ventas?preset=hoy')
            ->assertOk()
            ->assertJsonPath('data.resumen.ordenes', 1);

        // El admin de B no ve nada de A.
        Sanctum::actingAs($this->adminB);
        $this->getJson('/api/v1/reportes/ventas?preset=hoy')
            ->assertOk()
            ->assertJsonPath('data.resumen.ordenes', 0)
            ->assertJsonPath('data.resumen.total', 0);
    }

    public function test_la_auditoria_del_admin_es_solo_de_su_tenant(): void
    {
        Sanctum::actingAs($this->adminA);
        $this->getJson('/api/v1/auditoria?accion=orden.pagada')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        Sanctum::actingAs($this->adminB);
        $this->getJson('/api/v1/auditoria?accion=orden.pagada')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }
}
