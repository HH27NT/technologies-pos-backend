<?php

namespace Tests\Integration\Impresion;

use App\Jobs\EnviarComandaJob;
use App\Jobs\ImprimirTicketJob;
use App\Models\ConfiguracionEstablecimiento;
use App\Models\Establecimiento;
use App\Models\Orden;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M13 · La impresión automática (impresion_automatica) genera el documento y ENCOLA su
 * envío sin bloquear el cobro ni la comanda; sin la bandera no se genera nada automático.
 */
class ImpresionFlujoTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('flujoi');
        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
    }

    private function activarImpresionAutomatica(): void
    {
        ConfiguracionEstablecimiento::where('id_establecimiento', $this->establecimiento->id)
            ->update(['impresion_automatica' => true]);
    }

    private function efectivo(): int
    {
        return TipoPago::where('nombre', 'efectivo')->value('id');
    }

    public function test_cobro_con_impresion_automatica_encola_sin_bloquear(): void
    {
        $this->activarImpresionAutomatica();
        Queue::fake();

        $orden = $this->ordenAbiertaConTotal(100);
        $this->postJson("/api/v1/ordenes/{$orden->id}/pagos", ['id_tipo_pago' => $this->efectivo(), 'monto' => 100])
            ->assertCreated()
            ->assertJsonPath('data.estado_orden', 'pagada');

        // El cobro cerró la orden Y el ticket quedó registrado + encolado (no bloqueó).
        $this->assertDatabaseHas('ordenes', ['id' => $orden->id, 'estado' => 'pagada']);
        $this->assertDatabaseHas('tickets', ['id_orden' => $orden->id, 'tipo' => 'cobro']);
        Queue::assertPushed(ImprimirTicketJob::class);
    }

    public function test_comanda_automatica_al_confirmar(): void
    {
        $this->activarImpresionAutomatica();
        Queue::fake();

        $orden = $this->ordenAbiertaConTotal(100);
        $this->postJson("/api/v1/ordenes/{$orden->id}/comanda")->assertOk();

        $this->assertDatabaseHas('tickets', ['id_orden' => $orden->id, 'tipo' => 'comanda']);
        Queue::assertPushed(EnviarComandaJob::class);
    }

    public function test_sin_impresion_automatica_no_genera_ticket(): void
    {
        // impresion_automatica = false (default): el cobro no genera ticket automático.
        Queue::fake();

        $orden = $this->ordenAbiertaConTotal(100);
        $this->postJson("/api/v1/ordenes/{$orden->id}/pagos", ['id_tipo_pago' => $this->efectivo(), 'monto' => 100])
            ->assertCreated();

        $this->assertDatabaseMissing('tickets', ['id_orden' => $orden->id]);
        Queue::assertNotPushed(ImprimirTicketJob::class);
    }
}
