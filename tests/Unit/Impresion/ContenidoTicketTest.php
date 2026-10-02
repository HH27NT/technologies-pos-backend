<?php

namespace Tests\Unit\Impresion;

use App\Domain\Impresion\ContenidoTicket;
use App\Domain\Ordenes\Services\ConfirmarComandaService;
use App\Domain\Pagos\Services\RegistrarPagoService;
use App\Models\ConfiguracionEstablecimiento;
use App\Models\Establecimiento;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M13 · Construcción del contenido_json: la comanda no lleva precios; el ticket de cobro
 * incluye totales congelados y pagos; cabecera desde la configuración.
 */
class ContenidoTicketTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('cont');
        $this->enContextoDe($this->establecimiento->id);
        Auth::login($this->admin);
        $this->abrirCaja($this->admin);
    }

    private function config(): ConfiguracionEstablecimiento
    {
        return ConfiguracionEstablecimiento::where('id_establecimiento', $this->establecimiento->id)->first();
    }

    public function test_comanda_no_lleva_precios(): void
    {
        $orden = $this->ordenAbiertaConProducto($this->crearProducto(100), 2);
        app(ConfirmarComandaService::class)->confirmar($orden);
        $orden = $orden->fresh()->load(['detalles.producto', 'tipoOrden', 'mesa']);

        $contenido = ContenidoTicket::comanda($orden, $this->config());

        $this->assertSame('comanda', $contenido['tipo']);
        $this->assertNotEmpty($contenido['items']);
        $this->assertArrayHasKey('cantidad', $contenido['items'][0]);
        $this->assertArrayHasKey('producto', $contenido['items'][0]);
        $this->assertArrayNotHasKey('precio_unitario', $contenido['items'][0]);
    }

    public function test_cobro_incluye_totales_y_pagos(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);
        $efectivo = TipoPago::where('nombre', 'efectivo')->value('id');
        app(RegistrarPagoService::class)->registrar($orden, ['id_tipo_pago' => $efectivo, 'monto' => 100]);

        $orden = $orden->fresh()->load(['detalles.producto', 'tipoOrden', 'mesa', 'pagos.tipoPago']);
        $contenido = ContenidoTicket::cobro($orden, $this->config(), 'T000001');

        $this->assertSame('cobro', $contenido['tipo']);
        $this->assertSame('T000001', $contenido['folio_ticket']);
        $this->assertEqualsWithDelta(100, $contenido['totales']['total'], 0.001);
        $this->assertCount(1, $contenido['pagos']);
        $this->assertEqualsWithDelta(100, $contenido['pagos'][0]['monto'], 0.001);
        $this->assertArrayHasKey('precio_unitario', $contenido['items'][0]);
    }
}
