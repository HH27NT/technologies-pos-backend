<?php

namespace Tests\Feature\Catalogo;

use App\Models\Establecimiento;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M06+M07 · Lo que necesita la pantalla de Recetas para ser una lista de PRODUCTOS
 * y no de renglones: `?con_recetas=1` (los insumos de cada producto) y
 * `?sin_receta=1` (lo que falta por recetear).
 *
 * Antes la pantalla cruzaba `/recetas`, que pagina por renglón: una receta de cinco
 * insumos se partía entre dos páginas y el producto de la siguiente parecía no tener
 * ninguna. Un "sin receta" falso manda a capturar de nuevo algo que ya existe.
 */
class ProductoRecetaListaTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private int $idCategoria;

    private int $idUnidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('rec');
        Sanctum::actingAs($this->admin);

        $this->idCategoria = $this->postJson('/api/v1/categorias', ['nombre' => 'Barra'])->json('data.id');
        $this->idUnidad = UnidadMedida::whereNull('id_establecimiento')->where('nombre', 'Pieza')->value('id');
    }

    private function crearProducto(string $nombre): int
    {
        return $this->postJson('/api/v1/productos', [
            'nombre' => $nombre,
            'id_categoria' => $this->idCategoria,
            'precio_venta' => 50,
            'controla_inventario' => true,
        ])->assertCreated()->json('data.id');
    }

    private function crearInsumo(string $nombre): int
    {
        return $this->postJson('/api/v1/insumos', [
            'nombre' => $nombre,
            'id_unidad_medida' => $this->idUnidad,
            'costo_unitario' => 20,
        ])->assertCreated()->json('data.id');
    }

    private function recetear(int $idProducto, array $idsInsumo): void
    {
        $this->putJson('/api/v1/recetas/producto/'.$idProducto, [
            'insumos' => array_map(fn ($id) => ['id_insumo' => $id, 'cantidad' => 1], $idsInsumo),
        ])->assertOk();
    }

    public function test_con_recetas_trae_los_insumos_de_cada_producto(): void
    {
        $idProducto = $this->crearProducto('Michelada');
        $this->recetear($idProducto, [$this->crearInsumo('Cerveza'), $this->crearInsumo('Limón')]);

        $respuesta = $this->getJson('/api/v1/productos?con_recetas=1')->assertOk();

        $respuesta->assertJsonCount(2, 'data.0.recetas');
        // La unidad viaja con el insumo: la pantalla pinta "1 pza" sin ir al catálogo.
        $respuesta->assertJsonPath('data.0.recetas.0.insumo.unidad_medida.nombre', 'Pieza');
    }

    public function test_sin_el_parametro_el_producto_no_carga_recetas(): void
    {
        $idProducto = $this->crearProducto('Michelada');
        $this->recetear($idProducto, [$this->crearInsumo('Cerveza')]);

        // El POS pide este mismo índice en cada venta: cargar el BOM ahí sería peso muerto.
        $this->getJson('/api/v1/productos')->assertOk()->assertJsonMissingPath('data.0.recetas');
    }

    public function test_un_producto_sin_receta_la_trae_vacia_no_ausente(): void
    {
        $this->crearProducto('Agua');

        // Vacía y no ausente: la pantalla distingue "no tiene" de "no se pidió".
        $this->getJson('/api/v1/productos?con_recetas=1')
            ->assertOk()
            ->assertJsonCount(0, 'data.0.recetas');
    }

    public function test_sin_receta_filtra_los_que_faltan_por_recetear(): void
    {
        $idConReceta = $this->crearProducto('Michelada');
        $this->recetear($idConReceta, [$this->crearInsumo('Cerveza')]);
        $this->crearProducto('Agua');

        $nombres = array_column(
            $this->getJson('/api/v1/productos?controla_inventario=1&sin_receta=1')->assertOk()->json('data'),
            'nombre'
        );

        $this->assertSame(['Agua'], $nombres);
    }

    public function test_el_conteo_de_lo_que_falta_sale_del_meta(): void
    {
        $this->crearProducto('Agua');
        $this->crearProducto('Refresco');
        $idConReceta = $this->crearProducto('Michelada');
        $this->recetear($idConReceta, [$this->crearInsumo('Cerveza')]);

        // La pantalla pide una sola fila y lee el total: el aviso "faltan N" no
        // puede costar traerse el catálogo entero.
        $this->getJson('/api/v1/productos?controla_inventario=1&sin_receta=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_quitar_la_receta_vuelve_a_dejar_al_producto_en_los_que_faltan(): void
    {
        $idProducto = $this->crearProducto('Michelada');
        $this->recetear($idProducto, [$this->crearInsumo('Cerveza')]);
        $this->putJson('/api/v1/recetas/producto/'.$idProducto, ['insumos' => []])->assertOk();

        $this->getJson('/api/v1/productos?sin_receta=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_se_combina_con_el_buscador(): void
    {
        $this->crearProducto('Michelada preparada');
        $this->crearProducto('Agua');

        $nombres = array_column(
            $this->getJson('/api/v1/productos?sin_receta=1&buscar=michelada')->assertOk()->json('data'),
            'nombre'
        );

        $this->assertSame(['Michelada preparada'], $nombres);
    }

    public function test_la_receta_de_otro_tenant_no_se_asoma(): void
    {
        $idProducto = $this->crearProducto('Michelada');
        $this->recetear($idProducto, [$this->crearInsumo('Cerveza')]);

        ['admin' => $otroAdmin] = $this->nuevoTenant('ajeno');
        Sanctum::actingAs($otroAdmin);

        $this->getJson('/api/v1/productos?con_recetas=1')->assertOk()->assertJsonCount(0, 'data');
    }
}
