<?php

namespace Tests\Unit\Inventario;

use App\Domain\Inventario\Services\GestionarRecetaService;
use App\Models\Insumo;
use App\Models\Producto;
use App\Support\Exceptions\RecetaDuplicadaException;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class GestionarRecetaServiceTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Producto $producto;

    private Insumo $insumo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $est] = $this->nuevoTenant('bom');
        app(TenantContext::class)->set($est->id);

        $this->producto = Producto::factory()->create();
        $this->insumo = Insumo::factory()->create();
    }

    public function test_crea_linea_de_receta(): void
    {
        $receta = app(GestionarRecetaService::class)->crear([
            'id_producto' => $this->producto->id,
            'id_insumo' => $this->insumo->id,
            'cantidad' => 0.5,
        ]);

        $this->assertDatabaseHas('recetas_producto', ['id' => $receta->id, 'cantidad' => 0.5]);
        $this->assertDatabaseHas('auditoria', ['accion' => 'receta.creada', 'entidad_id' => $receta->id]);
    }

    public function test_rechaza_par_producto_insumo_duplicado(): void
    {
        $datos = ['id_producto' => $this->producto->id, 'id_insumo' => $this->insumo->id, 'cantidad' => 1];
        app(GestionarRecetaService::class)->crear($datos);

        $this->expectException(RecetaDuplicadaException::class);
        app(GestionarRecetaService::class)->crear($datos);
    }
}
