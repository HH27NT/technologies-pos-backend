<?php

namespace Tests\Unit\Impresion;

use App\Domain\Impresion\SelectorImpresora;
use App\Domain\Impresion\TipoTicket;
use App\Models\Establecimiento;
use App\Models\Impresora;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M13 · Selección de impresora por tipo de documento (D2): comanda → cocina/barra;
 * cobro → ticket/admin; sin impresora activa del tipo → null (PDF, P15).
 */
class SelectorImpresoraTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('impr');
        $this->enContextoDe($this->establecimiento->id);
    }

    private function crearImpresora(string $tipo, bool $activa = true): Impresora
    {
        return Impresora::create(['nombre' => "Impresora $tipo", 'tipo' => $tipo, 'activa' => $activa]);
    }

    public function test_comanda_prefiere_cocina_sobre_barra(): void
    {
        $this->crearImpresora('barra');
        $cocina = $this->crearImpresora('cocina');

        $this->assertSame($cocina->id, SelectorImpresora::para(TipoTicket::Comanda)?->id);
    }

    public function test_comanda_usa_barra_si_no_hay_cocina(): void
    {
        $barra = $this->crearImpresora('barra');

        $this->assertSame($barra->id, SelectorImpresora::para(TipoTicket::Comanda)?->id);
    }

    public function test_cobro_usa_impresora_de_tickets(): void
    {
        $this->crearImpresora('cocina');
        $ticket = $this->crearImpresora('ticket');

        $this->assertSame($ticket->id, SelectorImpresora::para(TipoTicket::Cobro)?->id);
    }

    public function test_sin_impresora_del_tipo_devuelve_null(): void
    {
        // Solo hay una impresora de cobro; para la comanda no hay candidata → PDF.
        $this->crearImpresora('ticket');

        $this->assertNull(SelectorImpresora::para(TipoTicket::Comanda));
    }

    public function test_ignora_impresoras_inactivas(): void
    {
        $this->crearImpresora('cocina', activa: false);

        $this->assertNull(SelectorImpresora::para(TipoTicket::Comanda));
    }
}
