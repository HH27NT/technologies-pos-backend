<?php

namespace Tests\Unit\Ordenes;

use App\Domain\Ordenes\Services\CrearOrdenService;
use App\Models\Orden;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M11 · Folio continuo por tenant (P12): correlativo que no reinicia ni duplica, y
 * aislado entre establecimientos. Se ejercita a través de CrearOrdenService (que es
 * quien usa el trait GeneraFolio dentro de su transacción).
 */
class GeneraFolioTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function service(): CrearOrdenService
    {
        return app(CrearOrdenService::class);
    }

    public function test_folio_es_continuo_por_tenant(): void
    {
        ['establecimiento' => $est, 'admin' => $admin] = $this->nuevoTenant('folio');
        $this->enContextoDe($est->id);
        Auth::login($admin);
        $this->abrirCaja($admin);

        $primera = $this->service()->crear(['id_tipo_orden' => $this->tipoOrdenId('barra')]);
        $segunda = $this->service()->crear(['id_tipo_orden' => $this->tipoOrdenId('barra')]);
        $tercera = $this->service()->crear(['id_tipo_orden' => $this->tipoOrdenId('barra')]);

        $this->assertSame('000001', $primera->folio);
        $this->assertSame('000002', $segunda->folio);
        $this->assertSame('000003', $tercera->folio);
    }

    public function test_folio_aislado_entre_tenants(): void
    {
        // Tenant A genera dos folios.
        ['establecimiento' => $estA, 'admin' => $adminA] = $this->nuevoTenant('folioa');
        $this->enContextoDe($estA->id);
        Auth::login($adminA);
        $this->abrirCaja($adminA);
        $this->service()->crear(['id_tipo_orden' => $this->tipoOrdenId('barra')]);
        $this->service()->crear(['id_tipo_orden' => $this->tipoOrdenId('barra')]);

        // Tenant B arranca su propia secuencia en 000001 (no continúa la de A).
        ['establecimiento' => $estB, 'admin' => $adminB] = $this->nuevoTenant('foliob');
        $this->enContextoDe($estB->id);
        Auth::login($adminB);
        $this->abrirCaja($adminB);
        $ordenB = $this->service()->crear(['id_tipo_orden' => $this->tipoOrdenId('barra')]);

        $this->assertSame('000001', $ordenB->folio);
        $this->assertSame($estB->id, $ordenB->id_establecimiento);
        // A conserva sus 2; B tiene 1.
        $this->assertSame(2, Orden::withoutGlobalScopes()->where('id_establecimiento', $estA->id)->count());
        $this->assertSame(1, Orden::withoutGlobalScopes()->where('id_establecimiento', $estB->id)->count());
    }
}
