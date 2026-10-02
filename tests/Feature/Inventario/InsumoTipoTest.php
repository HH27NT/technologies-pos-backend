<?php

namespace Tests\Feature\Inventario;

use App\Models\Establecimiento;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M08 · Clase del insumo (`controlado` / `consumo`) y sus consecuencias:
 * el índice filtra por ella y una receta no puede colgar de un insumo de consumo.
 */
class InsumoTipoTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private int $idUnidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('tip');
        $this->idUnidad = UnidadMedida::whereNull('id_establecimiento')->firstOrFail()->id;
    }

    /** @return array<string, mixed> */
    private function payload(string $nombre, ?string $tipo = null): array
    {
        $datos = ['nombre' => $nombre, 'id_unidad_medida' => $this->idUnidad];

        return $tipo === null ? $datos : $datos + ['tipo' => $tipo];
    }

    public function test_un_insumo_nace_controlado_si_no_se_dice_otra_cosa(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/insumos', $this->payload('Tequila blanco'))
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'controlado');
    }

    public function test_admin_marca_un_insumo_como_de_consumo(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/insumos', $this->payload('Tamarindo', 'consumo'))
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'consumo')
            ->json('data.id');

        $this->assertDatabaseHas('insumos', ['id' => $id, 'tipo' => 'consumo']);
    }

    public function test_el_tipo_solo_acepta_los_dos_valores_conocidos(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/insumos', $this->payload('Chamoy', 'al_tanteo'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo');
    }

    public function test_el_indice_filtra_por_tipo(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/insumos', $this->payload('Ron', 'controlado'))->assertCreated();
        $this->postJson('/api/v1/insumos', $this->payload('Sal de gusano', 'consumo'))->assertCreated();

        $controlados = $this->getJson('/api/v1/insumos?tipo=controlado')->assertOk()->json('data');
        $nombres = array_column($controlados, 'nombre');

        $this->assertContains('Ron', $nombres);
        $this->assertNotContains('Sal de gusano', $nombres);
    }

    public function test_una_receta_no_puede_colgar_de_un_insumo_de_consumo(): void
    {
        Sanctum::actingAs($this->admin);

        $idCategoria = $this->postJson('/api/v1/categorias', ['nombre' => 'Preparados'])->json('data.id');
        $idProducto = $this->postJson('/api/v1/productos', [
            'nombre' => 'Azulito', 'id_categoria' => $idCategoria, 'precio_venta' => 90,
            'controla_inventario' => true,
        ])->json('data.id');
        $idConsumo = $this->postJson('/api/v1/insumos', $this->payload('Hielo', 'consumo'))->json('data.id');

        // El insumo existe y es del tenant; lo que lo rechaza es su tipo.
        $this->postJson('/api/v1/recetas', [
            'id_producto' => $idProducto, 'id_insumo' => $idConsumo, 'cantidad' => 1,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_insumo');
    }

    public function test_el_atajo_del_alta_crea_su_insumo_como_controlado(): void
    {
        Sanctum::actingAs($this->admin);

        $idCategoria = $this->postJson('/api/v1/categorias', ['nombre' => 'Cervezas'])->json('data.id');
        $this->postJson('/api/v1/productos', [
            'nombre' => 'Corona', 'id_categoria' => $idCategoria, 'precio_venta' => 45,
            'insumo' => ['id_unidad_medida' => $this->idUnidad, 'costo_unitario' => 18],
        ])->assertCreated();

        // Lo que sale del almacén tal cual sí se descuenta al vender: nace controlado.
        $this->assertDatabaseHas('insumos', [
            'id_establecimiento' => $this->establecimiento->id,
            'nombre' => 'Corona',
            'tipo' => 'controlado',
        ]);
    }
}
