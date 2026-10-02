<?php

namespace Tests\Integration\Inventario;

use App\Domain\Inventario\Services\ReconciliarStockService;
use App\Models\Insumo;
use App\Models\UnidadMedida;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class InventarioLedgerTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    public function test_el_cache_de_stock_coincide_con_la_suma_del_ledger(): void
    {
        $this->seed();
        ['establecimiento' => $est, 'admin' => $admin] = $this->nuevoTenant('led');
        $idUnidad = UnidadMedida::whereNull('id_establecimiento')->firstOrFail()->id;

        Sanctum::actingAs($admin);
        $idInsumo = $this->postJson('/api/v1/insumos', ['nombre' => 'Vodka', 'id_unidad_medida' => $idUnidad])->json('data.id');

        $this->postJson('/api/v1/movimientos', ['id_insumo' => $idInsumo, 'tipo' => 'entrada', 'cantidad' => 60])->assertCreated();
        $this->postJson('/api/v1/movimientos', ['id_insumo' => $idInsumo, 'tipo' => 'merma', 'cantidad' => 7, 'motivo' => 'd'])->assertCreated();
        $this->postJson('/api/v1/movimientos', ['id_insumo' => $idInsumo, 'tipo' => 'ajuste', 'cantidad' => 4, 'motivo' => 'c'])->assertCreated();

        app(TenantContext::class)->set($est->id);
        $insumo = Insumo::findOrFail($idInsumo);
        $sumaLedger = app(ReconciliarStockService::class)->calcularDesdeLedger($idInsumo);

        $this->assertEquals(57, (float) $insumo->stock_actual); // 60 - 7 + 4
        $this->assertEquals((float) $insumo->stock_actual, $sumaLedger);
    }

    public function test_unidades_hibridas_y_aislamiento_entre_tenants(): void
    {
        $this->seed();
        $globales = UnidadMedida::whereNull('id_establecimiento')->count();

        ['establecimiento' => $estA, 'admin' => $adminA] = $this->nuevoTenant('uha');
        ['establecimiento' => $estB, 'admin' => $adminB] = $this->nuevoTenant('uhb');

        // A crea una unidad propia.
        Sanctum::actingAs($adminA);
        $this->postJson('/api/v1/unidades-medida', ['nombre' => 'Barril A'])->assertCreated();

        // A ve globales + su propia.
        $this->getJson('/api/v1/unidades-medida?per_page=100')->assertOk()
            ->assertJsonPath('meta.total', $globales + 1);

        // B ve solo las globales (no la propia de A).
        Sanctum::actingAs($adminB);
        $this->getJson('/api/v1/unidades-medida?per_page=100')->assertOk()
            ->assertJsonPath('meta.total', $globales);

        // Aislamiento de insumos: un insumo de A no es accesible para B (404).
        app(TenantContext::class)->set($estA->id);
        $insumoA = Insumo::factory()->create();
        app(TenantContext::class)->olvidar();

        Sanctum::actingAs($adminB);
        $this->getJson("/api/v1/insumos/{$insumoA->id}")->assertStatus(404);
    }
}
