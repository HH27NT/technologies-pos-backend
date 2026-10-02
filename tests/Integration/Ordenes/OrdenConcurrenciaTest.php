<?php

namespace Tests\Integration\Ordenes;

use App\Domain\Ordenes\Services\AnularOrdenService;
use App\Domain\Ordenes\Services\CrearOrdenService;
use App\Models\Orden;
use App\Support\Exceptions\MesaOcupadaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M11 · Concurrencia y auditoría de órdenes: una sola orden abierta por mesa
 * (verificación + bloqueo pesimista; en pgsql, índice único parcial), folio continuo
 * seguro, auditoría de orden.creada/orden.anulada, y aislamiento entre tenants.
 */
class OrdenConcurrenciaTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function crear(): CrearOrdenService
    {
        return app(CrearOrdenService::class);
    }

    public function test_no_se_permiten_dos_ordenes_abiertas_en_la_misma_mesa(): void
    {
        ['establecimiento' => $est, 'admin' => $admin] = $this->nuevoTenant('cc1');
        $this->enContextoDe($est->id);
        Auth::login($admin);
        $this->abrirCaja($admin);
        $mesa = $this->crearMesa(1);

        $this->crear()->crear(['id_tipo_orden' => $this->tipoOrdenId('mesa'), 'id_mesa' => $mesa->id]);

        try {
            $this->crear()->crear(['id_tipo_orden' => $this->tipoOrdenId('mesa'), 'id_mesa' => $mesa->id]);
            $this->fail('Se esperaba MesaOcupadaException.');
        } catch (MesaOcupadaException) {
            // esperado
        }

        $this->assertSame(1, Orden::where('id_mesa', $mesa->id)->where('estado', 'abierta')->count());
    }

    public function test_folio_continuo_y_auditoria_de_creacion(): void
    {
        ['establecimiento' => $est, 'admin' => $admin] = $this->nuevoTenant('cc2');
        $this->enContextoDe($est->id);
        Auth::login($admin);
        $this->abrirCaja($admin);

        $a = $this->crear()->crear(['id_tipo_orden' => $this->tipoOrdenId('barra')]);
        $b = $this->crear()->crear(['id_tipo_orden' => $this->tipoOrdenId('barra')]);

        $this->assertSame('000001', $a->folio);
        $this->assertSame('000002', $b->folio);

        $this->assertDatabaseHas('auditoria', [
            'id_establecimiento' => $est->id, 'accion' => 'orden.creada', 'entidad' => 'ordenes', 'entidad_id' => $a->id,
        ]);
    }

    public function test_auditoria_de_anulacion(): void
    {
        ['establecimiento' => $est, 'admin' => $admin] = $this->nuevoTenant('cc3');
        $this->enContextoDe($est->id);
        Auth::login($admin);
        $this->abrirCaja($admin);

        $orden = $this->crear()->crear(['id_tipo_orden' => $this->tipoOrdenId('barra')]);
        app(AnularOrdenService::class)->anular($orden, ['motivo' => 'walkout']);

        $this->assertDatabaseHas('auditoria', [
            'id_establecimiento' => $est->id, 'accion' => 'orden.anulada', 'entidad' => 'ordenes', 'entidad_id' => $orden->id,
        ]);
        $this->assertDatabaseHas('ordenes', ['id' => $orden->id, 'estado' => 'anulada']);
    }

    public function test_aislamiento_de_ordenes_entre_tenants(): void
    {
        ['establecimiento' => $estA, 'admin' => $adminA] = $this->nuevoTenant('cca');
        $this->enContextoDe($estA->id);
        Auth::login($adminA);
        $this->abrirCaja($adminA);
        $this->crear()->crear(['id_tipo_orden' => $this->tipoOrdenId('barra')]);

        ['establecimiento' => $estB, 'admin' => $adminB] = $this->nuevoTenant('ccb');
        $this->enContextoDe($estB->id);
        Auth::login($adminB);

        // B no ve las órdenes de A a través del TenantScope.
        $this->assertSame(0, Orden::count());
    }
}
