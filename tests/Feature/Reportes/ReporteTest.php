<?php

namespace Tests\Feature\Reportes;

use App\Domain\Pagos\Services\RegistrarPagoService;
use App\Models\Establecimiento;
use App\Models\Orden;
use App\Models\SesionCaja;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M16 · Reportes operativos (dashboard, ventas, medios de pago, caja) y su alcance por
 * rol (P21): el ADMIN ve todo el establecimiento; el OPERADOR solo su turno. Validación
 * de rango. Los reportes de gestión son solo del ADMIN.
 */
class ReporteTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('repf');
        $this->operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');

        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
    }

    private function efectivo(): int
    {
        return TipoPago::where('nombre', 'efectivo')->value('id');
    }

    private function ordenPagada(float $total = 100): Orden
    {
        $orden = $this->ordenAbiertaConTotal($total);
        app(RegistrarPagoService::class)->registrar($orden, ['id_tipo_pago' => $this->efectivo(), 'monto' => $total]);

        return $orden->fresh();
    }

    public function test_dashboard_del_dia_resume_ventas(): void
    {
        $this->ordenPagada(100);
        $this->ordenAbiertaConTotal(30); // queda abierta

        $this->getJson('/api/v1/reportes/dashboard')
            ->assertOk()
            ->assertJsonPath('data.resumen.ventas_total', 100)
            ->assertJsonPath('data.resumen.ordenes_pagadas', 1)
            ->assertJsonPath('data.resumen.ordenes_abiertas', 1);
    }

    /** El dashboard de decisiones es la landing del dueño: el gerente no lo ve (queda con el resto de reportes). */
    public function test_gerente_no_ve_el_dashboard_pero_operador_ni_siquiera_lo_intenta(): void
    {
        $gerente = $this->crearUsuarioEnTenant($this->establecimiento->id, 'gerente');
        Sanctum::actingAs($gerente);

        $this->getJson('/api/v1/reportes/dashboard')->assertForbidden();
        $this->getJson('/api/v1/reportes/ventas?preset=hoy')->assertOk();
    }

    public function test_ventas_suma_las_ordenes_pagadas_del_rango(): void
    {
        $this->ordenPagada(100);
        $this->ordenPagada(50);

        $this->getJson('/api/v1/reportes/ventas?preset=hoy')
            ->assertOk()
            ->assertJsonPath('data.resumen.ordenes', 2)
            ->assertJsonPath('data.resumen.total', 150);
    }

    public function test_medios_de_pago_agrupa_por_tipo(): void
    {
        $this->ordenPagada(100);

        $this->getJson('/api/v1/reportes/medios-pago?preset=hoy')
            ->assertOk()
            ->assertJsonPath('data.resumen.operaciones', 1)
            ->assertJsonPath('data.resumen.monto', 100)
            ->assertJsonPath('data.filas.0.medio', 'efectivo');
    }

    public function test_caja_lista_las_sesiones_del_rango(): void
    {
        $this->getJson('/api/v1/reportes/caja?preset=hoy')
            ->assertOk()
            ->assertJsonPath('data.resumen.sesiones', 1);
    }

    public function test_operador_solo_ve_su_turno(): void
    {
        // Turno del admin: 1 orden pagada; luego se cierra su sesión.
        $this->ordenPagada(100);
        SesionCaja::where('estado', 'abierta')->update(['estado' => 'cerrada', 'cerrada_at' => now()]);

        // Turno del operador: su propia sesión + 1 orden pagada.
        Sanctum::actingAs($this->operador);
        $this->abrirCaja($this->operador);
        $this->ordenPagada(50);

        // El admin ve las dos órdenes del establecimiento.
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/reportes/ventas?preset=hoy')
            ->assertOk()
            ->assertJsonPath('data.resumen.ordenes', 2)
            ->assertJsonPath('data.resumen.total', 150);

        // El operador solo ve la suya (P21).
        Sanctum::actingAs($this->operador);
        $this->getJson('/api/v1/reportes/ventas?preset=hoy')
            ->assertOk()
            ->assertJsonPath('data.resumen.ordenes', 1)
            ->assertJsonPath('data.resumen.total', 50);
    }

    public function test_operador_no_accede_a_reportes_de_gestion(): void
    {
        Sanctum::actingAs($this->operador);

        $this->getJson('/api/v1/reportes/inventario')->assertForbidden();
        $this->getJson('/api/v1/reportes/cancelaciones')->assertForbidden();
        $this->getJson('/api/v1/reportes/margen')->assertForbidden();
    }

    public function test_rango_invalido_devuelve_422(): void
    {
        $this->getJson('/api/v1/reportes/ventas?desde=2026-07-10&hasta=2026-07-01')
            ->assertStatus(422);
    }
}
