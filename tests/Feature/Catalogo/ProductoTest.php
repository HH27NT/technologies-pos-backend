<?php

namespace Tests\Feature\Catalogo;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class ProductoTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private int $idCategoria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('prod');
        Sanctum::actingAs($this->admin);
        $this->idCategoria = $this->postJson('/api/v1/categorias', ['nombre' => 'General'])->json('data.id');
    }

    public function test_admin_crea_producto(): void
    {
        $this->postJson('/api/v1/productos', [
            'id_categoria' => $this->idCategoria,
            'nombre' => 'Tarja',
            'precio_venta' => 45.50,
            'costo_referencia' => 20,
        ])->assertCreated()->assertJsonPath('data.nombre', 'Tarja');

        $this->assertDatabaseHas('productos', [
            'id_establecimiento' => $this->establecimiento->id,
            'nombre' => 'Tarja',
            'precio_venta' => 45.50,
        ]);
    }

    public function test_categoria_obligatoria(): void
    {
        $this->postJson('/api/v1/productos', ['nombre' => 'Sin Cat', 'precio_venta' => 10])
            ->assertStatus(422);
    }

    public function test_precio_no_puede_ser_negativo(): void
    {
        $this->postJson('/api/v1/productos', [
            'id_categoria' => $this->idCategoria, 'nombre' => 'Neg', 'precio_venta' => -5,
        ])->assertStatus(422);
    }

    public function test_no_acepta_categoria_de_otro_tenant(): void
    {
        // Categoría creada en OTRO establecimiento.
        ['admin' => $otroAdmin] = $this->nuevoTenant('ajeno');
        Sanctum::actingAs($otroAdmin);
        $idAjena = $this->postJson('/api/v1/categorias', ['nombre' => 'Ajena'])->json('data.id');

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/productos', [
            'id_categoria' => $idAjena, 'nombre' => 'Cruzado', 'precio_venta' => 10,
        ])->assertStatus(422);
    }

    public function test_producto_controla_inventario_sin_receta_se_crea(): void
    {
        // Contrato Sprint 4: el catálogo NO bloquea crear un producto con
        // controla_inventario=true aunque aún no tenga receta (eso llega en M07).
        $this->postJson('/api/v1/productos', [
            'id_categoria' => $this->idCategoria,
            'nombre' => 'Coctel',
            'precio_venta' => 120,
            'controla_inventario' => true,
        ])->assertCreated()->assertJsonPath('data.controla_inventario', true);
    }

    public function test_alternar_disponibilidad(): void
    {
        $id = $this->postJson('/api/v1/productos', [
            'id_categoria' => $this->idCategoria, 'nombre' => 'Agua', 'precio_venta' => 15,
        ])->json('data.id');

        $this->patchJson("/api/v1/productos/{$id}/activar", ['disponible' => false])
            ->assertOk()->assertJsonPath('data.disponible', false);
    }

    public function test_operador_lee_productos_pero_no_los_gestiona(): void
    {
        $this->postJson('/api/v1/productos', [
            'id_categoria' => $this->idCategoria, 'nombre' => 'Cerveza', 'precio_venta' => 48,
        ])->assertCreated();

        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        // Sin esto la rejilla del POS queda vacía: el operador podría agregar ítems a
        // la orden pero no vería los productos para elegirlos (`productos.ver`).
        $this->getJson('/api/v1/productos')
            ->assertOk()
            ->assertJsonPath('data.0.nombre', 'Cerveza');

        // El catálogo lo gestiona el admin (`productos.gestionar`).
        $this->postJson('/api/v1/productos', [
            'id_categoria' => $this->idCategoria, 'nombre' => 'Pirata', 'precio_venta' => 10,
        ])->assertStatus(403);
    }

    /**
     * Búsqueda de texto del índice (parámetro "buscar"). Existe para que la pantalla
     * de administración encuentre un producto sin pasar páginas; se resuelve en el
     * servidor porque filtrar la página visible en el cliente escondería justo lo que
     * se está buscando.
     */
    private function crearProducto(string $nombre, ?string $sku = null, ?int $idCategoria = null): int
    {
        return $this->postJson('/api/v1/productos', array_filter([
            'id_categoria' => $idCategoria ?? $this->idCategoria,
            'nombre' => $nombre,
            'precio_venta' => 50,
            'sku' => $sku,
        ]))->assertCreated()->json('data.id');
    }

    public function test_busca_por_nombre_sin_importar_mayusculas(): void
    {
        $this->crearProducto('Cerveza clara');
        $this->crearProducto('Mezcal espadin');

        // En PostgreSQL LIKE distingue mayúsculas; este caso es el que lo delata.
        $respuesta = $this->getJson('/api/v1/productos?buscar=CERVEZA')->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertSame('Cerveza clara', $respuesta->json('data.0.nombre'));
    }

    public function test_busca_por_sku(): void
    {
        $this->crearProducto('Cerveza clara', 'CRV-001');
        $this->crearProducto('Mezcal espadin', 'MZC-001');

        $respuesta = $this->getJson('/api/v1/productos?buscar=mzc')->assertOk();

        $this->assertCount(1, $respuesta->json('data'));
        $this->assertSame('Mezcal espadin', $respuesta->json('data.0.nombre'));
    }

    public function test_la_busqueda_no_interpreta_comodines(): void
    {
        $this->crearProducto('Cerveza clara');
        $this->crearProducto('Combo 50% descuento');

        // Sin escapar, "%" sería el comodín y devolvería el catálogo entero.
        $this->getJson('/api/v1/productos?buscar=%25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Combo 50% descuento');
    }

    public function test_la_busqueda_no_se_escapa_del_filtro_de_categoria(): void
    {
        $otraCategoria = $this->postJson('/api/v1/categorias', ['nombre' => 'Destilados'])->json('data.id');
        $this->crearProducto('Cerveza clara');
        $this->crearProducto('Cerveza artesanal', null, $otraCategoria);

        // El OR entre columnas debe quedar agrupado; si se escapa, el filtro de
        // categoría deja de valer y aparecen productos de otra categoría.
        $this->getJson("/api/v1/productos?buscar=cerveza&id_categoria={$this->idCategoria}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Cerveza clara');
    }

    public function test_busqueda_sin_resultados_devuelve_coleccion_vacia(): void
    {
        $this->crearProducto('Cerveza clara');

        $this->getJson('/api/v1/productos?buscar=tequila')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }
}
