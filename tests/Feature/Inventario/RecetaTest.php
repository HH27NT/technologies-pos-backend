<?php

namespace Tests\Feature\Inventario;

use App\Models\Establecimiento;
use App\Models\Insumo;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class RecetaTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Producto $producto;

    private Insumo $insumo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('bomf');

        app(TenantContext::class)->set($this->establecimiento->id);
        $idUnidad = UnidadMedida::whereNull('id_establecimiento')->firstOrFail()->id;
        $this->producto = Producto::factory()->create();
        $this->insumo = Insumo::factory()->create(['id_unidad_medida' => $idUnidad]);
        app(TenantContext::class)->olvidar();
    }

    public function test_admin_crea_y_lista_receta(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/recetas', [
            'id_producto' => $this->producto->id, 'id_insumo' => $this->insumo->id, 'cantidad' => 0.33,
        ])->assertCreated()->assertJsonPath('data.cantidad', '0.330');

        $this->getJson("/api/v1/recetas?id_producto={$this->producto->id}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_rechaza_par_producto_insumo_duplicado(): void
    {
        Sanctum::actingAs($this->admin);
        $datos = ['id_producto' => $this->producto->id, 'id_insumo' => $this->insumo->id, 'cantidad' => 1];

        $this->postJson('/api/v1/recetas', $datos)->assertCreated();
        $this->postJson('/api/v1/recetas', $datos)->assertStatus(409);
    }

    public function test_elimina_linea_de_receta(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/recetas', [
            'id_producto' => $this->producto->id, 'id_insumo' => $this->insumo->id, 'cantidad' => 1,
        ])->json('data.id');

        $this->deleteJson("/api/v1/recetas/{$id}")->assertOk();
        $this->assertDatabaseMissing('recetas_producto', ['id' => $id]);
    }

    public function test_operador_no_gestiona_recetas(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        $this->postJson('/api/v1/recetas', [
            'id_producto' => $this->producto->id, 'id_insumo' => $this->insumo->id, 'cantidad' => 1,
        ])->assertStatus(403);
    }
}
