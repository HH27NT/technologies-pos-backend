<?php

namespace Tests\Integration\Caja;

use App\Domain\Caja\Services\AbrirCajaService;
use App\Models\SesionCaja;
use App\Support\Exceptions\CajaYaAbiertaException;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class CajaConcurrenciaTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private function abrir(): AbrirCajaService
    {
        return app(AbrirCajaService::class);
    }

    public function test_no_se_permiten_dos_cajas_abiertas_en_el_mismo_establecimiento(): void
    {
        ['establecimiento' => $est, 'admin' => $admin] = $this->nuevoTenant('cc1');
        app(TenantContext::class)->set($est->id);
        Auth::login($admin);

        $this->abrir()->abrir(['monto_inicial' => 500]);

        // La verificación + bloqueo pesimista impide la segunda apertura (en pgsql,
        // además, el índice único parcial es la última salvaguarda).
        try {
            $this->abrir()->abrir(['monto_inicial' => 300]);
            $this->fail('Se esperaba CajaYaAbiertaException.');
        } catch (CajaYaAbiertaException) {
            // esperado
        }

        $this->assertSame(1, SesionCaja::where('estado', 'abierta')->count());
    }

    public function test_aislamiento_de_caja_entre_tenants(): void
    {
        // Tenant A abre su caja.
        ['establecimiento' => $estA, 'admin' => $adminA] = $this->nuevoTenant('cca');
        app(TenantContext::class)->set($estA->id);
        Auth::login($adminA);
        $this->abrir()->abrir(['monto_inicial' => 500]);

        // Tenant B no ve la caja de A y puede abrir la suya.
        ['establecimiento' => $estB, 'admin' => $adminB] = $this->nuevoTenant('ccb');
        app(TenantContext::class)->set($estB->id);
        Auth::login($adminB);

        $this->assertSame(0, SesionCaja::where('estado', 'abierta')->count());
        $sesionB = $this->abrir()->abrir(['monto_inicial' => 800]);

        $this->assertSame($estB->id, $sesionB->id_establecimiento);
        $this->assertSame(1, SesionCaja::where('estado', 'abierta')->count());
    }
}
