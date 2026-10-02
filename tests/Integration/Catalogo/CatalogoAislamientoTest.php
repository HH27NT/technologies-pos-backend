<?php

namespace Tests\Integration\Catalogo;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * Aislamiento multi-tenant del catálogo (DoD §3) y auditoría del catálogo (DoD §5).
 */
class CatalogoAislamientoTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $estA;

    private Usuario $adminA;

    private Usuario $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->estA, 'admin' => $this->adminA] = $this->nuevoTenant('A');
        ['admin' => $this->adminB] = $this->nuevoTenant('B');
    }

    public function test_un_admin_no_ve_ni_accede_al_catalogo_de_otro_tenant(): void
    {
        Sanctum::actingAs($this->adminA);
        $idCat = $this->postJson('/api/v1/categorias', ['nombre' => 'Solo A'])->json('data.id');
        $idProd = $this->postJson('/api/v1/productos', [
            'id_categoria' => $idCat, 'nombre' => 'Producto A', 'precio_venta' => 50,
        ])->json('data.id');

        // El admin de B no ve el catálogo de A...
        Sanctum::actingAs($this->adminB);
        $this->getJson('/api/v1/productos')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/categorias')->assertOk()->assertJsonCount(0, 'data');

        // ...y un acceso directo por id ajeno responde 404 (TenantScope).
        $this->getJson("/api/v1/productos/{$idProd}")->assertStatus(404);
        $this->getJson("/api/v1/categorias/{$idCat}")->assertStatus(404);
    }

    public function test_la_creacion_de_catalogo_queda_auditada(): void
    {
        Sanctum::actingAs($this->adminA);
        $idCat = $this->postJson('/api/v1/categorias', ['nombre' => 'Auditada'])->json('data.id');

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'categoria.creada',
            'entidad' => 'categorias_producto',
            'entidad_id' => $idCat,
            'id_establecimiento' => $this->estA->id,
        ]);
    }
}
