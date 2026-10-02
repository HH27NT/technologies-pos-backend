<?php

namespace Tests\Feature\Catalogo;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M06 · Filtro `?controla_inventario=` del índice de productos.
 * Lo pide la pantalla de recetas: una receta sobre un producto que no descuenta
 * inventario no hace nada al venderse, así que ese producto no debe ni ofrecerse.
 */
class ProductoFiltroInventarioTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private int $idCategoria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('fil');
        Sanctum::actingAs($this->admin);

        $this->idCategoria = $this->postJson('/api/v1/categorias', ['nombre' => 'Barra'])->json('data.id');
        $this->crearProducto('Azulito', true);
        $this->crearProducto('Sabritas', false);
    }

    private function crearProducto(string $nombre, bool $controlaInventario): void
    {
        $this->postJson('/api/v1/productos', [
            'nombre' => $nombre,
            'id_categoria' => $this->idCategoria,
            'precio_venta' => 50,
            'controla_inventario' => $controlaInventario,
        ])->assertCreated();
    }

    /** @return list<string> */
    private function nombresDe(string $url): array
    {
        return array_column($this->getJson($url)->assertOk()->json('data'), 'nombre');
    }

    public function test_filtra_los_productos_que_descuentan_inventario(): void
    {
        $nombres = $this->nombresDe('/api/v1/productos?controla_inventario=1');

        $this->assertSame(['Azulito'], $nombres);
    }

    public function test_el_cero_pide_los_que_no_descuentan(): void
    {
        // `filled` acepta el "0"; si se usara `boolean` el filtro se caería en silencio.
        $nombres = $this->nombresDe('/api/v1/productos?controla_inventario=0');

        $this->assertSame(['Sabritas'], $nombres);
    }

    public function test_sin_el_parametro_devuelve_todos(): void
    {
        $nombres = $this->nombresDe('/api/v1/productos');

        $this->assertEqualsCanonicalizing(['Azulito', 'Sabritas'], $nombres);
    }

    public function test_se_combina_con_el_buscador(): void
    {
        $this->crearProducto('Azul de curazao', true);

        $nombres = $this->nombresDe('/api/v1/productos?controla_inventario=1&buscar=azul');

        $this->assertEqualsCanonicalizing(['Azulito', 'Azul de curazao'], $nombres);
    }
}
