<?php

namespace Tests\Feature\Tenant;

use App\Models\Proveedor;
use App\Models\UnidadMedida;
use App\Support\Tenant\TenantContext;
use Database\Factories\EstablecimientoFactory;
use Database\Factories\ProveedorFactory;
use Database\Factories\UnidadMedidaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 0 · Test de aislamiento mínimo de la infraestructura multi-tenant.
 * Verifica TenantScope, autollenado de id_establecimiento y el scope híbrido
 * IncluyeGlobales de unidades_medida.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function contexto(): TenantContext
    {
        return app(TenantContext::class);
    }

    public function test_un_modelo_tenant_solo_devuelve_filas_del_establecimiento_actual(): void
    {
        $a = EstablecimientoFactory::new()->create();
        $b = EstablecimientoFactory::new()->create();

        $this->contexto()->set($a->id);
        ProveedorFactory::new()->count(2)->create();

        $this->contexto()->set($b->id);
        ProveedorFactory::new()->count(3)->create();

        $this->contexto()->set($a->id);
        $this->assertCount(2, Proveedor::all());

        $this->contexto()->set($b->id);
        $this->assertCount(3, Proveedor::all());

        // Sin contexto (super_admin / consola) no se aplica filtro de tenant.
        $this->contexto()->olvidar();
        $this->assertCount(5, Proveedor::all());
    }

    public function test_autollena_id_establecimiento_al_crear(): void
    {
        $a = EstablecimientoFactory::new()->create();
        $this->contexto()->set($a->id);

        $proveedor = ProveedorFactory::new()->create();

        $this->assertEquals($a->id, $proveedor->id_establecimiento);
    }

    public function test_unidades_medida_devuelve_globales_mas_propias(): void
    {
        $a = EstablecimientoFactory::new()->create();
        $b = EstablecimientoFactory::new()->create();

        // 2 unidades predefinidas globales (id_establecimiento NULL).
        UnidadMedidaFactory::new()->global()->count(2)->create();

        $this->contexto()->set($a->id);
        UnidadMedidaFactory::new()->create(); // propia de A (autollenada)

        $this->contexto()->set($b->id);
        UnidadMedidaFactory::new()->create(); // propia de B

        // A ve: 2 globales + 1 propia de A = 3; ninguna de B.
        $this->contexto()->set($a->id);
        $unidadesA = UnidadMedida::all();
        $this->assertCount(3, $unidadesA);
        $this->assertTrue($unidadesA->contains(fn ($u) => $u->id_establecimiento === $a->id));
        $this->assertFalse($unidadesA->contains(fn ($u) => $u->id_establecimiento === $b->id));

        // B ve: 2 globales + 1 propia de B = 3.
        $this->contexto()->set($b->id);
        $this->assertCount(3, UnidadMedida::all());
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            $this->contexto()->olvidar();
        }

        parent::tearDown();
    }
}
