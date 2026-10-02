<?php

namespace Tests\Feature\Catalogo;

use App\Models\Establecimiento;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M06+M08 · Atajo "esto sale del almacén tal cual". Un producto que se vende sin
 * preparar —una botella, una lata— necesita hoy tres pantallas: producto, insumo y
 * receta. En una cantina eso es casi todo el menú, así que la carga del menú que la
 * rejilla dejó en minutos volvía a irse en horas.
 *
 * Con el objeto `insumo` en el payload, el alta crea las tres cosas de una vez y
 * dentro de la misma transacción.
 */
class ProductoConInsumoTest extends TestCase
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

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('almacen');
        Sanctum::actingAs($this->admin);
        $this->idCategoria = $this->postJson('/api/v1/categorias', ['nombre' => 'Cervezas'])->json('data.id');
        // "Pieza" es global (id_establecimiento nulo): la siembra CatalogosGlobalesSeeder.
        $this->idUnidad = UnidadMedida::whereNull('id_establecimiento')->where('nombre', 'Pieza')->value('id');
    }

    private function alta(array $extra = []): array
    {
        return array_merge([
            'id_categoria' => $this->idCategoria,
            'nombre' => 'Corona 355ml',
            'precio_venta' => 45,
        ], $extra);
    }

    private function insumo(array $extra = []): array
    {
        return ['insumo' => array_merge(['id_unidad_medida' => $this->idUnidad], $extra)];
    }

    public function test_el_alta_crea_producto_insumo_y_receta_de_una_vez(): void
    {
        $id = $this->postJson('/api/v1/productos', $this->alta($this->insumo(['costo_unitario' => 22])))
            ->assertCreated()
            // Sin el interruptor encendido la receta no se usaría al cobrar.
            ->assertJsonPath('data.controla_inventario', true)
            ->json('data.id');

        $this->assertDatabaseHas('insumos', [
            'id_establecimiento' => $this->establecimiento->id,
            'nombre' => 'Corona 355ml',
            'id_unidad_medida' => $this->idUnidad,
            'costo_unitario' => 22,
            'stock_actual' => 0,
        ]);

        $idInsumo = DB::table('insumos')->where('nombre', 'Corona 355ml')->value('id');
        $this->assertDatabaseHas('recetas_producto', [
            'id_producto' => $id,
            'id_insumo' => $idInsumo,
            'cantidad' => 1,
        ]);
    }

    public function test_sin_el_objeto_insumo_el_alta_no_cambia(): void
    {
        $this->postJson('/api/v1/productos', $this->alta())->assertCreated();

        // Se comprueba en la tabla y no en la respuesta: el Resource devuelve null para
        // un campo que el alta no tocó, aunque el default de la columna sea false.
        $this->assertDatabaseHas('productos', ['nombre' => 'Corona 355ml', 'controla_inventario' => false]);
        $this->assertDatabaseCount('insumos', 0);
        $this->assertDatabaseCount('recetas_producto', 0);
    }

    public function test_reutiliza_el_insumo_que_ya_existe_con_ese_nombre(): void
    {
        // Duplicar el insumo repartiría el stock de la misma botella entre dos
        // registros y el inventario mentiría sin avisar.
        $idInsumo = $this->postJson('/api/v1/insumos', [
            'nombre' => 'Corona 355ml',
            'id_unidad_medida' => $this->idUnidad,
        ])->assertCreated()->json('data.id');

        $id = $this->postJson('/api/v1/productos', $this->alta($this->insumo()))
            ->assertCreated()->json('data.id');

        $this->assertDatabaseCount('insumos', 1);
        $this->assertDatabaseHas('recetas_producto', ['id_producto' => $id, 'id_insumo' => $idInsumo]);
    }

    public function test_reutiliza_sin_importar_mayusculas(): void
    {
        // En PostgreSQL la comparación distingue mayúsculas y en SQLite no: este caso
        // es el que delata que la búsqueda no se hizo en minúsculas por código.
        $this->postJson('/api/v1/insumos', [
            'nombre' => 'CORONA 355ML',
            'id_unidad_medida' => $this->idUnidad,
        ])->assertCreated();

        $this->postJson('/api/v1/productos', $this->alta($this->insumo()))->assertCreated();

        $this->assertDatabaseCount('insumos', 1);
    }

    public function test_el_costo_va_al_insumo_y_no_al_producto(): void
    {
        // `MargenQuery` ignora costo_referencia cuando hay inventario: guardarlo ahí
        // dejaría el reporte de utilidad con 100% de margen sin avisar.
        $this->postJson('/api/v1/productos', $this->alta(
            ['costo_referencia' => 99] + $this->insumo(['costo_unitario' => 22])
        ))->assertCreated()->assertJsonPath('data.costo_referencia', null);

        $this->assertDatabaseHas('insumos', ['nombre' => 'Corona 355ml', 'costo_unitario' => 22]);
    }

    public function test_la_unidad_es_obligatoria_si_se_pide_el_insumo(): void
    {
        $this->postJson('/api/v1/productos', $this->alta(['insumo' => ['costo_unitario' => 22]]))
            ->assertStatus(422);

        $this->assertDatabaseCount('productos', 0);
    }

    public function test_no_acepta_una_unidad_de_otro_establecimiento(): void
    {
        ['admin' => $otroAdmin] = $this->nuevoTenant('ajeno');
        Sanctum::actingAs($otroAdmin);
        $idAjena = $this->postJson('/api/v1/unidades-medida', ['nombre' => 'Barril ajeno', 'abreviacion' => 'brl'])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/productos', $this->alta($this->insumo(['id_unidad_medida' => $idAjena])))
            ->assertStatus(422);

        $this->assertDatabaseCount('recetas_producto', 0);
    }

    public function test_el_lote_tambien_crea_insumo_y_receta_por_fila(): void
    {
        $this->postJson('/api/v1/productos/lote', ['productos' => [
            $this->alta(['nombre' => 'Corona 355ml'] + $this->insumo(['costo_unitario' => 22])),
            $this->alta(['nombre' => 'Victoria 355ml'] + $this->insumo(['costo_unitario' => 20])),
            // Esta se vende sin control de inventario: no debe crear nada de almacén.
            $this->alta(['nombre' => 'Michelada preparada', 'precio_venta' => 75]),
        ]])->assertCreated();

        $this->assertDatabaseCount('productos', 3);
        $this->assertDatabaseCount('insumos', 2);
        $this->assertDatabaseCount('recetas_producto', 2);
    }

    public function test_una_fila_mala_no_deja_insumos_sueltos(): void
    {
        // La garantía del lote es todo o nada, y tiene que alcanzar a lo que el atajo
        // crea de lado: un insumo huérfano sería peor que no crear nada.
        $this->postJson('/api/v1/productos/lote', ['productos' => [
            $this->alta(['nombre' => 'Corona 355ml'] + $this->insumo()),
            $this->alta(['nombre' => 'Rota', 'precio_venta' => -1] + $this->insumo()),
        ]])->assertStatus(422);

        $this->assertDatabaseCount('productos', 0);
        $this->assertDatabaseCount('insumos', 0);
        $this->assertDatabaseCount('recetas_producto', 0);
    }

    public function test_el_producto_creado_asi_descuenta_al_cobrar(): void
    {
        // La prueba que importa: que todo esto sirva para algo al vender.
        $idProducto = $this->postJson('/api/v1/productos', $this->alta($this->insumo(['costo_unitario' => 22])))
            ->assertCreated()->json('data.id');
        $idInsumo = DB::table('insumos')->where('nombre', 'Corona 355ml')->value('id');

        $this->postJson('/api/v1/movimientos', [
            'id_insumo' => $idInsumo, 'tipo' => 'entrada', 'cantidad' => 10, 'costo_unitario' => 22,
        ])->assertCreated();

        // Comparado como número: SQLite (pruebas) devuelve "10" donde PostgreSQL
        // (producción) devuelve "10.000"; el decimal solo existe de verdad en uno.
        $this->assertEqualsWithDelta(10, (float) DB::table('insumos')->where('id', $idInsumo)->value('stock_actual'), 0.001);
        $this->assertDatabaseHas('recetas_producto', ['id_producto' => $idProducto, 'cantidad' => 1]);
    }
}
