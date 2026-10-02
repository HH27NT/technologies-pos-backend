<?php

namespace Tests\Unit\Caja;

use App\Domain\Caja\Services\CerrarCajaService;
use App\Models\Orden;
use App\Models\Pago;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Support\Exceptions\CajaConOrdenesAbiertasException;
use App\Support\Exceptions\MotivoDiferenciaRequeridoException;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class CerrarCajaServiceTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $est, 'admin' => $this->admin] = $this->nuevoTenant('cer');
        app(TenantContext::class)->set($est->id);
        Auth::login($this->admin);
    }

    private function service(): CerrarCajaService
    {
        return app(CerrarCajaService::class);
    }

    private function cajaAbierta(float $montoInicial = 500): SesionCaja
    {
        return SesionCaja::factory()->create([
            'id_usuario_apertura' => $this->admin->id,
            'monto_inicial' => $montoInicial,
        ]);
    }

    public function test_monto_sistema_suma_solo_efectivo(): void
    {
        $sesion = $this->cajaAbierta(500);
        // Orden pagada de la sesión con un pago en efectivo (200) y otro en tarjeta (999).
        $orden = Orden::factory()->pagada()->create(['id_sesion_caja' => $sesion->id, 'id_usuario' => $this->admin->id]);
        Pago::factory()->efectivo()->create(['id_orden' => $orden->id, 'id_usuario' => $this->admin->id, 'monto' => 200]);
        Pago::factory()->tarjeta()->create(['id_orden' => $orden->id, 'id_usuario' => $this->admin->id, 'monto' => 999]);

        // monto_sistema = 500 (inicial) + 200 (efectivo) = 700; la tarjeta NO entra (P6).
        $cerrada = $this->service()->cerrar($sesion, ['monto_contado' => 700]);

        $this->assertEquals(700, (float) $cerrada->monto_sistema);
        $this->assertEquals(0, (float) $cerrada->diferencia);
        $this->assertSame('cerrada', $cerrada->estado);
    }

    public function test_exige_motivo_si_hay_diferencia(): void
    {
        $sesion = $this->cajaAbierta(500);

        $this->expectException(MotivoDiferenciaRequeridoException::class);
        $this->service()->cerrar($sesion, ['monto_contado' => 480]); // diferencia -20, sin motivo
    }

    public function test_diferencia_cero_cierra_sin_motivo(): void
    {
        $sesion = $this->cajaAbierta(500);

        $cerrada = $this->service()->cerrar($sesion, ['monto_contado' => 500]);

        $this->assertEquals(0, (float) $cerrada->diferencia);
        $this->assertNull($cerrada->motivo);
        $this->assertSame('cerrada', $cerrada->estado);
    }

    public function test_diferencia_con_motivo_se_persiste(): void
    {
        $sesion = $this->cajaAbierta(500);

        $cerrada = $this->service()->cerrar($sesion, ['monto_contado' => 480, 'motivo' => 'faltante de caja']);

        $this->assertEquals(-20, (float) $cerrada->diferencia);
        $this->assertSame('faltante de caja', $cerrada->motivo);
    }

    public function test_bloquea_cierre_con_ordenes_abiertas(): void
    {
        $sesion = $this->cajaAbierta(500);
        Orden::factory()->create(['id_sesion_caja' => $sesion->id, 'id_usuario' => $this->admin->id]); // estado abierta

        $this->expectException(CajaConOrdenesAbiertasException::class);
        $this->service()->cerrar($sesion, ['monto_contado' => 500]);
    }
}
