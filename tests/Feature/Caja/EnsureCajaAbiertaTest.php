<?php

namespace Tests\Feature\Caja;

use App\Http\Middleware\EnsureCajaAbierta;
use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * El middleware se construye y prueba en el Sprint 6; se aplica a las rutas de venta
 * en el Sprint 7. Aquí se valida sobre una ruta de prueba con la cadena real.
 */
class EnsureCajaAbiertaTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('mid');

        Route::middleware(['auth:sanctum', 'resolve.tenant', 'tenant.activo', EnsureCajaAbierta::class])
            ->get('/api/v1/_test/requiere-caja', fn () => response()->json(['ok' => true]));
    }

    public function test_bloquea_la_venta_sin_caja_abierta(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/_test/requiere-caja')->assertStatus(409);
    }

    public function test_permite_la_venta_con_caja_abierta(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/caja/abrir', ['monto_inicial' => 500])->assertCreated();

        $this->getJson('/api/v1/_test/requiere-caja')->assertOk()->assertJsonPath('ok', true);
    }
}
