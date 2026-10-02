<?php

namespace Tests\Integration\Endurecimiento;

use App\Domain\Pagos\Services\RegistrarPagoService;
use App\Models\Establecimiento;
use App\Models\Insumo;
use App\Models\Producto;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * S12 · Aislamiento multi-tenant a través del API (§542): un actor del tenant B nunca
 * obtiene por id los recursos del tenant A. El TenantScope traduce el cruce en 404.
 */
class AislamientoApiTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private int $idOrdenA;

    private int $idTicketA;

    private int $idInsumoA;

    private int $idProductoA;

    private Usuario $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // Tenant A: crea recursos de todo tipo.
        ['establecimiento' => $establecimientoA, 'admin' => $adminA] = $this->nuevoTenant('aisapiA');
        $this->enContextoDe($establecimientoA->id);
        Sanctum::actingAs($adminA);
        $this->abrirCaja($adminA);

        $ordenA = $this->ordenAbiertaConTotal(100);
        $efectivo = TipoPago::where('nombre', 'efectivo')->value('id');
        app(RegistrarPagoService::class)->registrar($ordenA, ['id_tipo_pago' => $efectivo, 'monto' => 100]);

        $this->idOrdenA = $ordenA->id;
        $this->idTicketA = $this->postJson("/api/v1/ordenes/{$ordenA->id}/ticket")->json('data.id');
        $this->idInsumoA = Insumo::factory()->create()->id;
        $this->idProductoA = Producto::factory()->create()->id;

        // Tenant B: otro establecimiento y su admin.
        ['admin' => $this->adminB] = $this->nuevoTenant('aisapiB');
    }

    public function test_un_actor_de_b_no_obtiene_recursos_de_a(): void
    {
        Sanctum::actingAs($this->adminB);

        $this->getJson("/api/v1/ordenes/{$this->idOrdenA}")->assertNotFound();
        $this->getJson("/api/v1/tickets/{$this->idTicketA}")->assertNotFound();
        $this->getJson("/api/v1/insumos/{$this->idInsumoA}")->assertNotFound();
        $this->getJson("/api/v1/insumos/{$this->idInsumoA}/kardex")->assertNotFound();
        $this->getJson("/api/v1/productos/{$this->idProductoA}")->assertNotFound();
    }
}
