<?php

namespace Tests\Feature\Catalogo;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M06 · Alta de productos por lote (POST /productos/lote). Existe para la carga
 * inicial del menú: un bar que estrena el sistema teclea ochenta productos y hacerlo
 * de uno en uno son ochenta peticiones con fallos parciales imposibles de explicar.
 *
 * La garantía que aquí se prueba es **todo o nada**: si una fila no valida, no se
 * crea ninguna. Guardar lo válido y saltar lo malo dejaría al usuario sin saber qué
 * entró y, como el catálogo no exige nombre único, reintentar duplicaría lo creado.
 */
class ProductoLoteTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private int $idCategoria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('lote');
        Sanctum::actingAs($this->admin);
        $this->idCategoria = $this->postJson('/api/v1/categorias', ['nombre' => 'Cervezas'])->json('data.id');
    }

    /** @param array<int, array<string, mixed>> $productos */
    private function enviarLote(array $productos): TestResponse
    {
        return $this->postJson('/api/v1/productos/lote', ['productos' => $productos]);
    }

    private function fila(string $nombre, float $precio = 50): array
    {
        return ['id_categoria' => $this->idCategoria, 'nombre' => $nombre, 'precio_venta' => $precio];
    }

    public function test_crea_varios_productos_de_una_vez(): void
    {
        $respuesta = $this->enviarLote([
            $this->fila('Corona', 45),
            $this->fila('Victoria', 42),
            $this->fila('Modelo', 48),
        ])->assertCreated();

        $this->assertCount(3, $respuesta->json('data'));
        $this->assertSame('3 productos creados correctamente.', $respuesta->json('message'));
        $this->assertDatabaseCount('productos', 3);
        // La categoría viaja anidada: la rejilla muestra lo creado sin pedir la lista otra vez.
        $this->assertSame('Cervezas', $respuesta->json('data.0.categoria.nombre'));
    }

    public function test_una_fila_invalida_no_crea_ninguna(): void
    {
        $respuesta = $this->enviarLote([
            $this->fila('Corona', 45),
            ['id_categoria' => $this->idCategoria, 'nombre' => '', 'precio_venta' => 30],
            $this->fila('Modelo', 48),
        ])->assertStatus(422);

        // El error apunta a la fila exacta: sin el índice, la rejilla no sabría cuál marcar.
        $this->assertArrayHasKey('productos.1.nombre', $respuesta->json('errors'));
        $this->assertDatabaseCount('productos', 0);
    }

    public function test_rechaza_precio_negativo_con_el_indice_de_la_fila(): void
    {
        $respuesta = $this->enviarLote([
            $this->fila('Corona', 45),
            $this->fila('Michelada', -10),
        ])->assertStatus(422);

        // La clave del error lleva puntos ("productos.1.precio_venta"), así que se lee
        // del arreglo: json() con esa ruta la interpretaría como niveles anidados.
        $errores = $respuesta->json('errors');
        $this->assertSame(
            'El precio de venta no puede ser negativo.',
            $errores['productos.1.precio_venta'][0]
        );
        $this->assertDatabaseCount('productos', 0);
    }

    public function test_rechaza_categoria_de_otro_tenant(): void
    {
        ['admin' => $otroAdmin] = $this->nuevoTenant('ajeno');
        Sanctum::actingAs($otroAdmin);
        $idAjena = $this->postJson('/api/v1/categorias', ['nombre' => 'Ajena'])->json('data.id');

        Sanctum::actingAs($this->admin);
        $this->enviarLote([
            ['id_categoria' => $idAjena, 'nombre' => 'Cruzado', 'precio_venta' => 10],
        ])->assertStatus(422);

        $this->assertDatabaseCount('productos', 0);
    }

    public function test_acepta_los_campos_opcionales_por_fila(): void
    {
        $this->enviarLote([
            [
                'id_categoria' => $this->idCategoria,
                'nombre' => 'Coctel de la casa',
                'precio_venta' => 120,
                'costo_referencia' => 40,
                'sku' => 'CTL-01',
                'controla_inventario' => true,
                'descripcion' => 'Receta propia',
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('productos', [
            'nombre' => 'Coctel de la casa',
            'sku' => 'CTL-01',
            'controla_inventario' => true,
        ]);
    }

    public function test_lote_vacio_es_rechazado(): void
    {
        $this->enviarLote([])->assertStatus(422);
    }

    public function test_tope_de_cien_filas_por_lote(): void
    {
        // Sin tope, un pegado accidental de miles de filas se vuelve una petición
        // que ni el servidor ni la persona pueden explicar cuando falle.
        $filas = [];
        for ($i = 0; $i < 101; $i++) {
            $filas[] = $this->fila('Producto '.$i);
        }

        $this->enviarLote($filas)->assertStatus(422);
        $this->assertDatabaseCount('productos', 0);
    }

    public function test_admite_nombres_repetidos_y_no_deduplica(): void
    {
        // Decisión de negocio (2026-09-04): ante nombres repetidos se avisa en la
        // rejilla y la persona audita; el API no bloquea, porque el mismo nombre en
        // dos categorías con precios distintos es legítimo.
        $this->enviarLote([$this->fila('Michelada', 60), $this->fila('Michelada', 75)])
            ->assertCreated();

        $this->assertDatabaseCount('productos', 2);
    }

    public function test_el_operador_no_puede_cargar_el_menu(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        $this->enviarLote([$this->fila('Pirata')])->assertStatus(403);
        $this->assertDatabaseCount('productos', 0);
    }

    public function test_cada_producto_del_lote_queda_auditado(): void
    {
        $this->enviarLote([$this->fila('Corona'), $this->fila('Victoria')])->assertCreated();

        // El historial de un producto no debe depender de cómo se dio de alta: un
        // asiento por producto, no uno por lote. Se cuenta solo lo de productos porque
        // la tabla ya trae asientos del alta de categoría y del propio tenant.
        $this->assertSame(2, DB::table('auditoria')->where('entidad', 'productos')->count());
        $this->assertDatabaseHas('auditoria', ['accion' => 'producto.creado', 'entidad' => 'productos']);
    }
}
