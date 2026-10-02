<?php

namespace Tests\Feature\Inventario;

use App\Models\Establecimiento;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class UnidadMedidaTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('um');
    }

    public function test_listado_incluye_globales_y_propias(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/unidades-medida', ['nombre' => 'Botella', 'abreviacion' => 'bot'])->assertCreated();

        // Las globales del seed (id_establecimiento NULL) + la propia recién creada.
        $globales = UnidadMedida::whereNull('id_establecimiento')->count();
        $total = $this->getJson('/api/v1/unidades-medida?per_page=100')->assertOk()->json('meta.total');

        $this->assertSame($globales + 1, $total);
    }

    public function test_no_se_puede_editar_una_unidad_global(): void
    {
        Sanctum::actingAs($this->admin);
        $global = UnidadMedida::whereNull('id_establecimiento')->firstOrFail();

        $this->putJson("/api/v1/unidades-medida/{$global->id}", ['nombre' => 'Hackeada'])
            ->assertStatus(403); // la policy rechaza escritura sobre globales
    }

    public function test_crea_y_elimina_unidad_propia(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/unidades-medida', ['nombre' => 'Caja', 'abreviacion' => 'cja'])
            ->assertCreated()->assertJsonPath('data.es_global', false)->json('data.id');

        $this->deleteJson("/api/v1/unidades-medida/{$id}")->assertOk();
        $this->assertDatabaseMissing('unidades_medida', ['id' => $id]);
    }

    public function test_no_elimina_unidad_en_uso(): void
    {
        Sanctum::actingAs($this->admin);
        $idUnidad = $this->postJson('/api/v1/unidades-medida', ['nombre' => 'Litro propio'])->json('data.id');
        $this->postJson('/api/v1/insumos', [
            'nombre' => 'Agua', 'id_unidad_medida' => $idUnidad,
        ])->assertCreated();

        $this->deleteJson("/api/v1/unidades-medida/{$idUnidad}")->assertStatus(409);
    }
}
