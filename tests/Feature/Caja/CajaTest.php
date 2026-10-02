<?php

namespace Tests\Feature\Caja;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class CajaTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('caja');
    }

    public function test_admin_abre_caja(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 500])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'abierta')
            ->assertJsonPath('data.monto_inicial', '500.00');

        $this->assertDatabaseHas('sesiones_caja', [
            'id_establecimiento' => $this->establecimiento->id, 'estado' => 'abierta',
        ]);
    }

    public function test_operador_abre_caja(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 300])->assertCreated();
    }

    public function test_actual_devuelve_null_sin_caja_y_la_sesion_si_esta_abierta(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/caja/actual')->assertOk()->assertJsonPath('data', null);

        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 500])->assertCreated();

        $this->getJson('/api/v1/caja/actual')->assertOk()->assertJsonPath('data.estado', 'abierta');
    }

    public function test_mesero_lee_estado_de_caja_sin_montos_y_no_ve_historico(): void
    {
        // Bug: el mesero (solo `caja.ver`, sin abrir/cerrar) veía la caja "cerrada" porque
        // /caja/actual le daba 403. Ahora la lee para la compuerta del POS, pero SIN el
        // efectivo en cajón (monto_inicial/monto_sistema), y sigue sin ver el histórico.
        $mesero = $this->crearUsuarioEnTenant($this->establecimiento->id, 'mesero');

        // Sin caja abierta: null (igual que para el admin).
        Sanctum::actingAs($mesero);
        $this->getJson('/api/v1/caja/actual')->assertOk()->assertJsonPath('data', null);

        // El admin abre la caja del turno.
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 500])->assertCreated();

        // El mesero la ve abierta, pero el payload no filtra montos.
        Sanctum::actingAs($mesero);
        $this->getJson('/api/v1/caja/actual')
            ->assertOk()
            ->assertJsonPath('data.estado', 'abierta')
            ->assertJsonMissingPath('data.monto_inicial')
            ->assertJsonMissingPath('data.monto_sistema')
            ->assertJsonMissingPath('data.usuario_apertura');

        // El histórico financiero sigue vedado al mesero.
        $this->getJson('/api/v1/caja/historico')->assertForbidden();
    }

    public function test_cierra_con_diferencia_cero(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 500])->assertCreated();

        $this->postJson('/api/v1/caja/cerrar', ['monto_contado' => 500])
            ->assertOk()
            ->assertJsonPath('data.estado', 'cerrada')
            ->assertJsonPath('data.diferencia', '0.00');
    }

    public function test_cierra_con_diferencia_y_motivo(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 500])->assertCreated();

        $this->postJson('/api/v1/caja/cerrar', ['monto_contado' => 480, 'motivo' => 'faltante'])
            ->assertOk()
            ->assertJsonPath('data.diferencia', '-20.00')
            ->assertJsonPath('data.motivo', 'faltante');
    }

    public function test_cierre_con_diferencia_sin_motivo_devuelve_422(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 500])->assertCreated();

        $this->postJson('/api/v1/caja/cerrar', ['monto_contado' => 480])->assertStatus(422);

        // La sesión sigue abierta: el cierre no se aplicó.
        $this->assertDatabaseHas('sesiones_caja', ['id_establecimiento' => $this->establecimiento->id, 'estado' => 'abierta']);
    }

    public function test_segunda_apertura_devuelve_409(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 500])->assertCreated();

        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 300])->assertStatus(409);
    }

    public function test_caja_cerrada_no_se_reabre(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 500])->assertCreated();
        $this->postJson('/api/v1/caja/cerrar', ['monto_contado' => 500])->assertOk();

        // Ya no hay caja abierta que cerrar.
        $this->postJson('/api/v1/caja/cerrar', ['monto_contado' => 500])->assertStatus(404);
        // Pero sí se puede abrir una caja nueva (nuevo ciclo).
        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 700])->assertCreated();
    }

    public function test_historico_lista_las_sesiones_del_establecimiento(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 500])->assertCreated();
        $this->postJson('/api/v1/caja/cerrar', ['monto_contado' => 500])->assertOk();
        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 700])->assertCreated();

        $this->getJson('/api/v1/caja/historico')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
