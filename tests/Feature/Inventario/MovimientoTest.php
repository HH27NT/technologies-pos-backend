<?php

namespace Tests\Feature\Inventario;

use App\Models\Establecimiento;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class MovimientoTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private int $idInsumo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('movf');
        $idUnidad = UnidadMedida::whereNull('id_establecimiento')->firstOrFail()->id;

        Sanctum::actingAs($this->admin);
        $this->idInsumo = $this->postJson('/api/v1/insumos', ['nombre' => 'Tequila', 'id_unidad_medida' => $idUnidad])->json('data.id');
    }

    public function test_admin_registra_entrada(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/movimientos', ['id_insumo' => $this->idInsumo, 'tipo' => 'entrada', 'cantidad' => 100])
            ->assertCreated()
            ->assertJsonPath('data.stock_resultante', '100.000');

        $this->assertDatabaseHas('insumos', ['id' => $this->idInsumo, 'stock_actual' => 100]);
    }

    public function test_operador_registra_merma_pero_no_entrada_ni_ajuste(): void
    {
        // El admin deja stock para que la merma del operador sea válida.
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/movimientos', ['id_insumo' => $this->idInsumo, 'tipo' => 'entrada', 'cantidad' => 50])->assertCreated();

        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        $this->postJson('/api/v1/movimientos', ['id_insumo' => $this->idInsumo, 'tipo' => 'merma', 'cantidad' => 5, 'motivo' => 'derrame'])
            ->assertCreated();

        // Entrada/ajuste no son directos para el operador: desde M14 exigen el bloque de
        // override (login+contraseña de admin). Sin él → 422 de validación (antes 403).
        $this->postJson('/api/v1/movimientos', ['id_insumo' => $this->idInsumo, 'tipo' => 'entrada', 'cantidad' => 10, 'motivo' => 'x'])
            ->assertStatus(422);
        $this->postJson('/api/v1/movimientos', ['id_insumo' => $this->idInsumo, 'tipo' => 'ajuste', 'cantidad' => 10, 'motivo' => 'corrección'])
            ->assertStatus(422);
    }

    public function test_motivo_obligatorio_en_ajuste_merma_rotura_y_consumo(): void
    {
        Sanctum::actingAs($this->admin);

        // Regla global 19: toda merma, rotura, ajuste y consumo se justifica (R2).
        foreach (['ajuste', 'merma', 'rotura', 'consumo_interno'] as $tipo) {
            $this->postJson('/api/v1/movimientos', ['id_insumo' => $this->idInsumo, 'tipo' => $tipo, 'cantidad' => 3])
                ->assertStatus(422);
        }
    }

    public function test_entrada_no_exige_motivo(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/movimientos', ['id_insumo' => $this->idInsumo, 'tipo' => 'entrada', 'cantidad' => 3])
            ->assertCreated();
    }

    public function test_merma_mayor_al_stock_devuelve_422(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/movimientos', ['id_insumo' => $this->idInsumo, 'tipo' => 'entrada', 'cantidad' => 2])->assertCreated();

        $this->postJson('/api/v1/movimientos', ['id_insumo' => $this->idInsumo, 'tipo' => 'merma', 'cantidad' => 5, 'motivo' => 'x'])
            ->assertStatus(422);

        $this->assertDatabaseHas('insumos', ['id' => $this->idInsumo, 'stock_actual' => 2]);
    }

    public function test_cantidad_debe_ser_positiva(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/movimientos', ['id_insumo' => $this->idInsumo, 'tipo' => 'entrada', 'cantidad' => 0])
            ->assertStatus(422);
    }
}
