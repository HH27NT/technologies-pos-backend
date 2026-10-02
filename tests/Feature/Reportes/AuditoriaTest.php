<?php

namespace Tests\Feature\Reportes;

use App\Domain\Pagos\Services\RegistrarPagoService;
use App\Models\Establecimiento;
use App\Models\Orden;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M15/M16 · Consulta de auditoría: el ADMIN ve la de su establecimiento; el OPERADOR no
 * accede; el SUPER_ADMIN ve la global de la plataforma.
 */
class AuditoriaTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('audf');
        $this->operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');

        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);

        // Genera hechos auditados (orden.pago_registrado, orden.pagada).
        $orden = $this->ordenAbiertaConTotal(100);
        $efectivo = TipoPago::where('nombre', 'efectivo')->value('id');
        app(RegistrarPagoService::class)->registrar($orden, ['id_tipo_pago' => $efectivo, 'monto' => 100]);
    }

    public function test_admin_consulta_la_auditoria_de_su_tenant(): void
    {
        $this->getJson('/api/v1/auditoria?accion=orden.pagada')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.accion', 'orden.pagada');
    }

    public function test_operador_no_consulta_auditoria(): void
    {
        Sanctum::actingAs($this->operador);

        $this->getJson('/api/v1/auditoria')->assertForbidden();
    }

    public function test_admin_no_accede_a_la_auditoria_global(): void
    {
        $this->getJson('/api/v1/auditoria/global')->assertForbidden();
    }

    public function test_super_admin_consulta_la_auditoria_global(): void
    {
        // El super_admin (id_establecimiento null) queda fuera del TenantScope activo.
        $super = Usuario::withoutGlobalScopes()->where('email', 'super@pos.local')->firstOrFail();
        Sanctum::actingAs($super);

        $this->getJson('/api/v1/auditoria/global')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
