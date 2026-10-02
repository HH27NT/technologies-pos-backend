<?php

namespace Tests\Feature\Impresion;

use App\Domain\Pagos\Services\RegistrarPagoService;
use App\Models\Establecimiento;
use App\Models\Impresora;
use App\Models\Orden;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M13 · Generación del ticket de cobro, fallback PDF (P15), reimpresión a ambos roles
 * sin autorización pero auditada (P14), vista previa y aislamiento por tenant.
 */
class TicketTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('tickf');
        $this->operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');

        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
    }

    private function ordenPagada(float $total = 100): Orden
    {
        $orden = $this->ordenAbiertaConTotal($total);
        $efectivo = TipoPago::where('nombre', 'efectivo')->value('id');
        app(RegistrarPagoService::class)->registrar($orden, ['id_tipo_pago' => $efectivo, 'monto' => $total]);

        return $orden->fresh();
    }

    public function test_genera_ticket_de_cobro_y_enruta_a_impresora(): void
    {
        Impresora::create(['nombre' => 'Caja', 'tipo' => 'ticket', 'activa' => true]);
        $orden = $this->ordenPagada();

        $this->postJson("/api/v1/ordenes/{$orden->id}/ticket")
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'cobro')
            ->assertJsonPath('data.es_pdf', false)
            ->assertJsonPath('data.folio_ticket', 'T000001');

        $this->assertDatabaseHas('tickets', ['id_orden' => $orden->id, 'tipo' => 'cobro']);
    }

    public function test_no_genera_ticket_de_orden_no_pagada(): void
    {
        $orden = $this->ordenAbiertaConTotal(100); // sigue abierta

        $this->postJson("/api/v1/ordenes/{$orden->id}/ticket")->assertStatus(422);
    }

    public function test_sin_impresora_el_ticket_es_pdf(): void
    {
        $orden = $this->ordenPagada();

        $this->postJson("/api/v1/ordenes/{$orden->id}/ticket")
            ->assertCreated()
            ->assertJsonPath('data.es_pdf', true)
            ->assertJsonPath('data.id_impresora', null);
    }

    public function test_vista_previa_devuelve_contenido(): void
    {
        $orden = $this->ordenPagada();
        $idTicket = $this->postJson("/api/v1/ordenes/{$orden->id}/ticket")->json('data.id');

        $this->getJson("/api/v1/tickets/{$idTicket}")
            ->assertOk()
            ->assertJsonPath('data.contenido_json.tipo', 'cobro')
            ->assertJsonPath('data.contenido_json.totales.total', 100);
    }

    public function test_el_ticket_imprime_a_quien_atendio_no_a_la_cuenta_de_la_terminal(): void
    {
        // Terminal compartida: la orden la abre la cuenta de la tablet (el admin) pero la firmó
        // Ana con su PIN. El comprobante del cliente debe llevar el nombre de Ana.
        $ana = $this->crearUsuarioEnTenant($this->establecimiento->id, 'mesero', ['nombre' => 'Ana']);
        $this->enContextoDe($this->establecimiento->id); // el helper olvida el contexto al salir
        $orden = $this->ordenPagada();
        $orden->update(['id_mesero' => $ana->id]);

        $idTicket = $this->postJson("/api/v1/ordenes/{$orden->id}/ticket")->json('data.id');

        $this->getJson("/api/v1/tickets/{$idTicket}")
            ->assertOk()
            ->assertJsonPath('data.contenido_json.atendio', 'Ana');
    }

    public function test_sin_firma_el_ticket_imprime_la_cuenta_que_atendio(): void
    {
        // Dispositivo por mesero: la cuenta ES la persona, así que el ticket la nombra igual.
        $orden = $this->ordenPagada();
        $idTicket = $this->postJson("/api/v1/ordenes/{$orden->id}/ticket")->json('data.id');

        $this->getJson("/api/v1/tickets/{$idTicket}")
            ->assertOk()
            ->assertJsonPath('data.contenido_json.atendio', $this->admin->nombre);
    }

    public function test_reimpresion_se_audita(): void
    {
        $orden = $this->ordenPagada();
        $idTicket = $this->postJson("/api/v1/ordenes/{$orden->id}/ticket")->json('data.id');

        $this->postJson("/api/v1/tickets/{$idTicket}/reimprimir")->assertOk();

        $this->assertDatabaseHas('auditoria', ['accion' => 'ticket.reimpreso', 'entidad' => 'tickets', 'entidad_id' => $idTicket]);
    }

    public function test_operador_puede_reimprimir_sin_autorizacion(): void
    {
        $orden = $this->ordenPagada();
        $idTicket = $this->postJson("/api/v1/ordenes/{$orden->id}/ticket")->json('data.id');

        // P14: la reimpresión está permitida a ambos roles sin autorización.
        Sanctum::actingAs($this->operador);
        $this->postJson("/api/v1/tickets/{$idTicket}/reimprimir")->assertOk();
    }

    public function test_aislamiento_entre_tenants(): void
    {
        $orden = $this->ordenPagada();
        $idTicket = $this->postJson("/api/v1/ordenes/{$orden->id}/ticket")->json('data.id');

        ['admin' => $adminB] = $this->nuevoTenant('otrot');
        Sanctum::actingAs($adminB);

        $this->getJson("/api/v1/tickets/{$idTicket}")->assertNotFound();
    }
}
