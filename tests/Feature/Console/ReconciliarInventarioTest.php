<?php

namespace Tests\Feature\Console;

use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class ReconciliarInventarioTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Insumo $insumo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $est] = $this->nuevoTenant('reccmd');
        app(TenantContext::class)->set($est->id);

        // Cache corrupto a propósito (999) frente a un ledger que suma 25.
        $this->insumo = Insumo::factory()->create(['nombre' => 'Whisky', 'stock_actual' => 999]);
        MovimientoInventario::factory()->create(['id_insumo' => $this->insumo->id, 'tipo' => 'entrada', 'cantidad' => 30]);
        MovimientoInventario::factory()->create(['id_insumo' => $this->insumo->id, 'tipo' => 'merma', 'cantidad' => 5]);
    }

    public function test_corrige_la_divergencia_cache_ledger(): void
    {
        $this->artisan('inventario:reconciliar')
            ->assertExitCode(0);

        $this->assertEquals(25, (float) $this->insumo->fresh()->stock_actual);
    }

    public function test_dry_run_reporta_pero_no_persiste(): void
    {
        $this->artisan('inventario:reconciliar --dry-run')
            ->assertExitCode(0);

        // El cache sigue corrupto: dry-run no toca la BD.
        $this->assertEquals(999, (float) $this->insumo->fresh()->stock_actual);
    }
}
