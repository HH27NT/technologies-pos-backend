<?php

namespace Tests\Feature\Inventario;

use App\Models\Establecimiento;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M07 · `PUT /recetas/producto/{idProducto}`: la receta completa en una operación.
 * Una receta es un conjunto de renglones (un azulito lleva alcohol, curazao y limón),
 * y escribirla renglón por renglón deja al producto a medio recomponer para el cobro.
 */
class RecetaCompletaTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private int $idProducto;

    /** @var array<string, int> */
    private array $insumos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('rec');
        Sanctum::actingAs($this->admin);

        $idUnidad = UnidadMedida::whereNull('id_establecimiento')->firstOrFail()->id;
        $idCategoria = $this->postJson('/api/v1/categorias', ['nombre' => 'Preparados'])->json('data.id');

        $this->idProducto = $this->postJson('/api/v1/productos', [
            'nombre' => 'Azulito', 'id_categoria' => $idCategoria,
            'precio_venta' => 90, 'controla_inventario' => true,
        ])->json('data.id');

        foreach (['Alcohol', 'Curazao', 'Limón', 'Jarabe'] as $nombre) {
            $this->insumos[$nombre] = $this->postJson('/api/v1/insumos', [
                'nombre' => $nombre, 'id_unidad_medida' => $idUnidad,
            ])->json('data.id');
        }
    }

    /** @param  array<string, float|int>  $porNombre */
    private function guardar(array $porNombre): TestResponse
    {
        $insumos = [];
        foreach ($porNombre as $nombre => $cantidad) {
            $insumos[] = ['id_insumo' => $this->insumos[$nombre], 'cantidad' => $cantidad];
        }

        return $this->putJson("/api/v1/recetas/producto/{$this->idProducto}", ['insumos' => $insumos]);
    }

    public function test_guarda_una_receta_de_varios_insumos_de_una_vez(): void
    {
        $this->guardar(['Alcohol' => 60, 'Curazao' => 30, 'Limón' => 20])
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('message', 'Receta guardada con 3 insumos.');

        $this->assertDatabaseCount('recetas_producto', 3);
    }

    public function test_reemplaza_la_receta_entera_en_la_segunda_llamada(): void
    {
        $this->guardar(['Alcohol' => 60, 'Curazao' => 30])->assertOk();

        // Sale Curazao, entra Jarabe, cambia la cantidad de Alcohol.
        $this->guardar(['Alcohol' => 45, 'Jarabe' => 15])->assertOk()->assertJsonCount(2, 'data');

        $this->assertDatabaseHas('recetas_producto', [
            'id_producto' => $this->idProducto, 'id_insumo' => $this->insumos['Alcohol'], 'cantidad' => 45,
        ]);
        $this->assertDatabaseHas('recetas_producto', [
            'id_producto' => $this->idProducto, 'id_insumo' => $this->insumos['Jarabe'],
        ]);
        $this->assertDatabaseMissing('recetas_producto', [
            'id_producto' => $this->idProducto, 'id_insumo' => $this->insumos['Curazao'],
        ]);
    }

    public function test_una_lista_vacia_borra_la_receta(): void
    {
        $this->guardar(['Alcohol' => 60])->assertOk();

        $this->putJson("/api/v1/recetas/producto/{$this->idProducto}", ['insumos' => []])
            ->assertOk()
            ->assertJsonPath('message', 'Receta eliminada.');

        $this->assertDatabaseCount('recetas_producto', 0);
    }

    public function test_el_insumo_repetido_devuelve_422_y_no_un_choque_de_indice(): void
    {
        $this->putJson("/api/v1/recetas/producto/{$this->idProducto}", [
            'insumos' => [
                ['id_insumo' => $this->insumos['Alcohol'], 'cantidad' => 30],
                ['id_insumo' => $this->insumos['Alcohol'], 'cantidad' => 30],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('insumos.1.id_insumo');

        $this->assertDatabaseCount('recetas_producto', 0);
    }

    public function test_no_deja_meter_un_insumo_de_consumo(): void
    {
        $idUnidad = UnidadMedida::whereNull('id_establecimiento')->firstOrFail()->id;
        $idTanteo = $this->postJson('/api/v1/insumos', [
            'nombre' => 'Chamoy', 'id_unidad_medida' => $idUnidad, 'tipo' => 'consumo',
        ])->json('data.id');

        $this->putJson("/api/v1/recetas/producto/{$this->idProducto}", [
            'insumos' => [['id_insumo' => $idTanteo, 'cantidad' => 5]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('insumos.0.id_insumo');
    }

    public function test_una_cantidad_invalida_no_guarda_nada(): void
    {
        $this->guardar(['Alcohol' => 60])->assertOk();

        $this->putJson("/api/v1/recetas/producto/{$this->idProducto}", [
            'insumos' => [
                ['id_insumo' => $this->insumos['Curazao'], 'cantidad' => 30],
                ['id_insumo' => $this->insumos['Limón'], 'cantidad' => 0],
            ],
        ])->assertStatus(422);

        // La receta anterior queda intacta: la validación corre antes de tocar nada.
        $this->assertDatabaseCount('recetas_producto', 1);
        $this->assertDatabaseHas('recetas_producto', ['id_insumo' => $this->insumos['Alcohol']]);
    }

    public function test_un_producto_de_otro_establecimiento_devuelve_404(): void
    {
        ['admin' => $otroAdmin] = $this->nuevoTenant('otro');
        Sanctum::actingAs($otroAdmin);

        $this->putJson("/api/v1/recetas/producto/{$this->idProducto}", ['insumos' => []])
            ->assertNotFound();
    }
}
