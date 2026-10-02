<?php

namespace Tests\Feature\Reportes;

use App\Domain\Ordenes\Services\CancelarItemService;
use App\Domain\Pagos\Services\RegistrarPagoService;
use App\Models\Establecimiento;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Orden;
use App\Models\Producto;
use App\Models\RecetaProducto;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M16 · Reportes de gestión (solo ADMIN): inventario, cancelaciones y margen. El margen
 * resuelve la fuente de costo por producto (P20): `costo_referencia` cuando no controla
 * inventario; costo de los insumos de la receta cuando sí.
 */
class ReporteGestionTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('gestf');
        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);
    }

    private function pagar(Orden $orden, float $total): void
    {
        $efectivo = TipoPago::where('nombre', 'efectivo')->value('id');
        app(RegistrarPagoService::class)->registrar($orden, ['id_tipo_pago' => $efectivo, 'monto' => $total]);
    }

    public function test_inventario_reporta_stock_bajo_y_mermas(): void
    {
        Insumo::factory()->conStock(50)->create(['nombre' => 'Ron', 'stock_minimo' => 10]);
        $bajo = Insumo::factory()->conStock(1)->create(['nombre' => 'Limón', 'stock_minimo' => 10]);

        MovimientoInventario::create([
            'id_insumo' => $bajo->id,
            'id_usuario' => $this->admin->id,
            'tipo' => 'merma',
            'cantidad' => 2,
            'stock_resultante' => 1,
            'motivo' => 'Rotura de prueba',
        ]);

        $this->getJson('/api/v1/reportes/inventario?preset=hoy')
            ->assertOk()
            ->assertJsonPath('data.resumen.insumos', 2)
            ->assertJsonPath('data.resumen.stock_bajo', 1)
            ->assertJsonPath('data.resumen.mermas', 1)
            ->assertJsonPath('data.resumen.cantidad_mermada', 2);
    }

    public function test_cancelaciones_lista_los_renglones_cancelados(): void
    {
        $orden = $this->ordenAbiertaConProducto($this->crearProducto(100), 2);
        $item = $orden->detalles()->first();

        app(CancelarItemService::class)->cancelar($orden->fresh(), $item, ['motivo' => 'Cliente se retiró']);

        $this->getJson('/api/v1/reportes/cancelaciones?preset=hoy')
            ->assertOk()
            ->assertJsonPath('data.resumen.total', 1)
            ->assertJsonPath('data.resumen.items_cancelados', 1)
            ->assertJsonPath('data.filas.0.motivo', 'Cliente se retiró');
    }

    public function test_margen_usa_la_fuente_de_costo_por_producto(): void
    {
        // Producto sin control de inventario → costo = costo_referencia (40).
        $sinControl = Producto::factory()->create([
            'precio_venta' => 100, 'costo_referencia' => 40, 'controla_inventario' => false,
        ]);
        $ordenA = $this->ordenAbiertaConProducto($sinControl, 1);
        $this->pagar($ordenA, 100);

        // Producto con receta → costo = Σ(cantidad × costo_unitario) = 3 × 10 = 30.
        $insumo = Insumo::factory()->conStock(100)->create(['costo_unitario' => 10]);
        $conReceta = Producto::factory()->controlaInventario()->create(['precio_venta' => 100]);
        RecetaProducto::create(['id_producto' => $conReceta->id, 'id_insumo' => $insumo->id, 'cantidad' => 3]);
        $ordenB = $this->ordenAbiertaConProducto($conReceta, 1);
        $this->pagar($ordenB, 100);

        // Ingreso 200, costo 40 + 30 = 70, margen 130.
        $this->getJson('/api/v1/reportes/margen?preset=hoy')
            ->assertOk()
            ->assertJsonPath('data.resumen.ingreso', 200)
            ->assertJsonPath('data.resumen.costo', 70)
            ->assertJsonPath('data.resumen.margen', 130);
    }

    /**
     * Un costo que nadie capturó no vale cero: sale `null` en la fila (la UI lo pinta
     * "—") y el resumen lo cuenta. El `margen_pct` se anula porque un porcentaje da a
     * entender que el costo está completo — de ahí venía el "100% de utilidad".
     */
    public function test_margen_no_inventa_un_costo_que_no_se_capturo(): void
    {
        // Con costo: se vende en 100 y cuesta 30 → margen 70.
        $insumoConCosto = Insumo::factory()->conStock(100)->create(['costo_unitario' => 10]);
        $medido = Producto::factory()->controlaInventario()->create(['precio_venta' => 100]);
        RecetaProducto::create(['id_producto' => $medido->id, 'id_insumo' => $insumoConCosto->id, 'cantidad' => 3]);
        $this->pagar($this->ordenAbiertaConProducto($medido, 1), 100);

        // Su insumo no tiene costo capturado: el costo del producto es desconocido.
        $insumoSinCosto = Insumo::factory()->conStock(100)->create(['costo_unitario' => null]);
        $sinCosto = Producto::factory()->controlaInventario()->create(['precio_venta' => 100]);
        RecetaProducto::create(['id_producto' => $sinCosto->id, 'id_insumo' => $insumoSinCosto->id, 'cantidad' => 1]);
        $this->pagar($this->ordenAbiertaConProducto($sinCosto, 1), 100);

        $respuesta = $this->getJson('/api/v1/reportes/margen?preset=hoy')->assertOk();

        // El de costo conocido va primero; el desconocido, al final y en null.
        $respuesta
            ->assertJsonPath('data.filas.0.costo', 30)
            ->assertJsonPath('data.filas.0.margen', 70)
            ->assertJsonPath('data.filas.1.costo', null)
            ->assertJsonPath('data.filas.1.margen', null)
            ->assertJsonPath('data.filas.1.margen_pct', null);

        // El resumen suma lo que sí se sabe, dice cuántos faltan y no publica el %.
        $respuesta
            ->assertJsonPath('data.resumen.ingreso', 200)
            ->assertJsonPath('data.resumen.costo', 30)
            ->assertJsonPath('data.resumen.productos_sin_costo', 1)
            ->assertJsonPath('data.resumen.margen_pct', null);
    }

    /** Un producto que controla inventario pero no tiene receta tampoco vale cero. */
    public function test_margen_marca_sin_costo_al_producto_sin_receta(): void
    {
        $sinReceta = Producto::factory()->controlaInventario()->create(['precio_venta' => 80]);
        $this->pagar($this->ordenAbiertaConProducto($sinReceta, 1), 80);

        // Nadie tiene costo conocido: el resumen tampoco inventa un total.
        $this->getJson('/api/v1/reportes/margen?preset=hoy')
            ->assertOk()
            ->assertJsonPath('data.filas.0.costo', null)
            ->assertJsonPath('data.resumen.productos_sin_costo', 1)
            ->assertJsonPath('data.resumen.costo', null)
            ->assertJsonPath('data.resumen.margen', null)
            ->assertJsonPath('data.resumen.ingreso', 80);
    }

    /** Y el que no controla inventario, si nadie le puso `costo_referencia`. */
    public function test_margen_marca_sin_costo_al_producto_sin_costo_referencia(): void
    {
        $sinReferencia = Producto::factory()->create([
            'precio_venta' => 50, 'costo_referencia' => null, 'controla_inventario' => false,
        ]);
        $this->pagar($this->ordenAbiertaConProducto($sinReferencia, 1), 50);

        $this->getJson('/api/v1/reportes/margen?preset=hoy')
            ->assertOk()
            ->assertJsonPath('data.filas.0.costo', null)
            ->assertJsonPath('data.resumen.productos_sin_costo', 1);
    }
}
