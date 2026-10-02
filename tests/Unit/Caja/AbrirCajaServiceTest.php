<?php

namespace Tests\Unit\Caja;

use App\Domain\Caja\Services\AbrirCajaService;
use App\Models\SesionCaja;
use App\Support\Exceptions\CajaYaAbiertaException;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class AbrirCajaServiceTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $est, 'admin' => $admin] = $this->nuevoTenant('abc');
        app(TenantContext::class)->set($est->id);
        Auth::login($admin);
    }

    private function service(): AbrirCajaService
    {
        return app(AbrirCajaService::class);
    }

    public function test_abre_caja_con_monto_inicial(): void
    {
        $sesion = $this->service()->abrir(['monto_inicial' => 500]);

        $this->assertSame('abierta', $sesion->estado);
        $this->assertEquals(500, (float) $sesion->monto_inicial);
        $this->assertDatabaseHas('sesiones_caja', ['id' => $sesion->id, 'estado' => 'abierta']);
    }

    public function test_rechaza_una_segunda_apertura(): void
    {
        $this->service()->abrir(['monto_inicial' => 500]);

        $this->expectException(CajaYaAbiertaException::class);
        $this->service()->abrir(['monto_inicial' => 300]);
    }

    public function test_la_segunda_apertura_no_crea_otra_sesion(): void
    {
        $this->service()->abrir(['monto_inicial' => 500]);

        try {
            $this->service()->abrir(['monto_inicial' => 300]);
        } catch (CajaYaAbiertaException) {
            // esperado
        }

        $this->assertSame(1, SesionCaja::count());
    }
}
