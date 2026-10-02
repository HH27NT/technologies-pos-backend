<?php

namespace Tests\Unit\Pagos;

use App\Domain\Pagos\Services\RegistrarPagoService;
use App\Models\Establecimiento;
use App\Models\Pago;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M12 · Pago dividido por monto (P8), cierre al saldar, cambio en sobrepago efectivo,
 * idempotencia por referencia (D1) y sin propina (P9).
 */
class RegistrarPagoServiceTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('pago');
        $this->enContextoDe($this->establecimiento->id);
        Auth::login($this->admin);
        $this->abrirCaja($this->admin);
    }

    private function service(): RegistrarPagoService
    {
        return app(RegistrarPagoService::class);
    }

    private function tipoPago(string $nombre = 'efectivo'): int
    {
        return TipoPago::where('nombre', $nombre)->value('id');
    }

    public function test_pago_dividido_cierra_la_orden_al_saldar(): void
    {
        $orden = $this->ordenAbiertaConTotal(100); // total 100

        $r1 = $this->service()->registrar($orden, ['id_tipo_pago' => $this->tipoPago(), 'monto' => 60]);
        $this->assertEqualsWithDelta(40, $r1['saldo'], 0.001);
        $this->assertSame('abierta', $orden->fresh()->estado);

        $r2 = $this->service()->registrar($orden, ['id_tipo_pago' => $this->tipoPago(), 'monto' => 40]);
        $this->assertEqualsWithDelta(0, $r2['saldo'], 0.001);
        $this->assertSame('pagada', $orden->fresh()->estado);
    }

    public function test_cambio_en_sobrepago_efectivo(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);

        // Recibe 150 en efectivo sobre un saldo de 100: aplica 100, cambio 50.
        $r = $this->service()->registrar($orden, ['id_tipo_pago' => $this->tipoPago('efectivo'), 'monto' => 150]);

        $this->assertEqualsWithDelta(50, $r['cambio'], 0.001);
        $this->assertEquals(100, (float) $r['pago']->monto); // se almacena solo lo aplicado
        $this->assertSame('pagada', $orden->fresh()->estado);
    }

    public function test_idempotencia_por_referencia_no_recobra(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);
        $datos = ['id_tipo_pago' => $this->tipoPago(), 'monto' => 50, 'referencia' => 'INTENTO-1'];

        $primero = $this->service()->registrar($orden, $datos);
        $segundo = $this->service()->registrar($orden, $datos); // reintento de red

        $this->assertSame($primero['pago']->id, $segundo['pago']->id);
        $this->assertTrue($segundo['idempotente']);
        // Un solo pago asentado: no hubo doble cobro.
        $this->assertSame(1, Pago::where('id_orden', $orden->id)->count());
    }

    public function test_no_escribe_propina(): void
    {
        $orden = $this->ordenAbiertaConTotal(100);

        $r = $this->service()->registrar($orden, ['id_tipo_pago' => $this->tipoPago(), 'monto' => 100]);

        $this->assertNull($r['pago']->propina); // P9: sin propina en V1
    }
}
