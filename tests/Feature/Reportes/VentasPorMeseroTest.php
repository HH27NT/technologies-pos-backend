<?php

namespace Tests\Feature\Reportes;

use App\Models\Establecimiento;
use App\Models\Pago;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M16 · Ventas por mesero.
 *
 * Es el reporte que da sentido al PIN: comprueba que la firma guardada se convierte en el
 * número que el dueño quería ver, y que **ese número es correcto en los dos modos de
 * operación** sin que el reporte sepa cuál está activo.
 *
 * El eje de estas pruebas es que **atender y cobrar son dos columnas distintas**: quien cierra
 * la mesa de un compañero no se queda con su venta.
 */
class VentasPorMeseroTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $ana;

    private Usuario $beto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('vxmesero');
        $this->ana = $this->crearUsuarioEnTenant($this->establecimiento->id, 'mesero', ['nombre' => 'Ana']);
        $this->beto = $this->crearUsuarioEnTenant($this->establecimiento->id, 'mesero', ['nombre' => 'Beto']);

        $this->enContextoDe($this->establecimiento->id);
        $this->abrirCaja($this->admin);
    }

    /**
     * Cobra una orden completa. `$firmante` = quien firmó con PIN (terminal compartida) o
     * null (dispositivo por mesero, donde la cuenta ya es la persona). Atiende y cobra la
     * misma persona; para separarlos está `cobrarOrdenDe()`.
     */
    private function cobrar(float $monto, Usuario $cuenta, ?Usuario $firmante = null): void
    {
        $this->cobrarOrdenDe($monto, $cuenta, $firmante, $firmante);
    }

    /**
     * El caso que separa las dos columnas: `$atendio` abre la mesa y `$cobro` la cierra.
     * Ambas firmas viven en tablas distintas (`ordenes.id_mesero` y `pagos.id_mesero`).
     */
    private function cobrarOrdenDe(float $monto, Usuario $cuenta, ?Usuario $atendio, ?Usuario $cobro): void
    {
        // La orden nace de la cuenta que la abre (el helper toma el usuario autenticado).
        Sanctum::actingAs($cuenta);

        $orden = $this->ordenAbiertaConTotal($monto);
        $orden->update(['id_usuario' => $cuenta->id, 'id_mesero' => $atendio?->id]);

        Pago::factory()->create([
            'id_orden' => $orden->id,
            'id_usuario' => $cuenta->id,
            'id_mesero' => $cobro?->id,
            'monto' => $monto,
            'pagado_at' => now(),
        ]);
    }

    /** @return Collection<string, array<string, mixed>> */
    private function filas(): Collection
    {
        Sanctum::actingAs($this->admin);

        return collect(
            $this->getJson('/api/v1/reportes/ventas-por-mesero?preset=hoy')->assertOk()->json('data.filas')
        )->keyBy('mesero');
    }

    public function test_con_terminal_compartida_atribuye_a_quien_firmo_no_a_la_cuenta(): void
    {
        // Las tres ventas salen de la MISMA cuenta de terminal (la del admin), firmadas por
        // personas distintas. Sin `id_mesero`, el reporte diría "admin: 600".
        $this->cobrar(100, $this->admin, $this->ana);
        $this->cobrar(200, $this->admin, $this->ana);
        $this->cobrar(300, $this->admin, $this->beto);

        $filas = $this->filas();

        $this->assertEqualsWithDelta(300.0, $filas['Ana']['monto_atendido'], 0.001);
        $this->assertSame(2, $filas['Ana']['ordenes']);
        $this->assertEqualsWithDelta(300.0, $filas['Beto']['monto_atendido'], 0.001);
        $this->assertArrayNotHasKey($this->admin->nombre, $filas->all());
    }

    public function test_con_dispositivo_por_mesero_atribuye_a_la_cuenta(): void
    {
        // Sin firma: cada quien cobra desde su propia cuenta. La misma consulta debe acertar.
        $this->cobrar(150, $this->ana);
        $this->cobrar(250, $this->beto);

        $filas = $this->filas();

        $this->assertEqualsWithDelta(150.0, $filas['Ana']['monto_atendido'], 0.001);
        $this->assertEqualsWithDelta(250.0, $filas['Beto']['monto_atendido'], 0.001);
        // Sin terminal compartida las dos columnas coinciden: cada quien cobra lo suyo.
        $this->assertEqualsWithDelta(150.0, $filas['Ana']['monto_cobrado'], 0.001);
    }

    public function test_los_dos_modos_conviven_en_el_mismo_reporte(): void
    {
        // Un local puede migrar de un modo al otro a media semana: el histórico no se rompe.
        $this->cobrar(100, $this->ana);                 // antes: cuenta propia
        $this->cobrar(400, $this->admin, $this->ana);   // después: terminal compartida

        $filas = $this->filas();

        $this->assertEqualsWithDelta(500.0, $filas['Ana']['monto_atendido'], 0.001);
        $this->assertSame(2, $filas['Ana']['ordenes']);
    }

    public function test_separa_a_quien_atendio_de_quien_cobro(): void
    {
        // El caso de todas las noches: Ana atiende la mesa y Beto se la cierra. La venta es
        // de Ana; el dinero pasó por Beto. Acreditársela a Beto le robaría la venta a Ana.
        $this->cobrarOrdenDe(500, $this->admin, $this->ana, $this->beto);

        $filas = $this->filas();

        $this->assertEqualsWithDelta(500.0, $filas['Ana']['monto_atendido'], 0.001);
        $this->assertSame(1, $filas['Ana']['ordenes']);
        $this->assertSame(0, $filas['Ana']['cobros']);
        $this->assertEqualsWithDelta(0.0, $filas['Ana']['monto_cobrado'], 0.001);

        $this->assertEqualsWithDelta(500.0, $filas['Beto']['monto_cobrado'], 0.001);
        $this->assertSame(1, $filas['Beto']['cobros']);
        $this->assertSame(0, $filas['Beto']['ordenes']);
        $this->assertEqualsWithDelta(0.0, $filas['Beto']['monto_atendido'], 0.001);
    }

    public function test_las_dos_columnas_cuadran_con_el_total_sin_duplicarlo(): void
    {
        // La invariante que hace legible el reporte: son dos repartos del MISMO dinero.
        // Cada columna totaliza la venta del rango; sumarlas entre sí la duplicaría.
        $this->cobrarOrdenDe(500, $this->admin, $this->ana, $this->beto);
        $this->cobrar(300, $this->admin, $this->ana);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/reportes/ventas-por-mesero?preset=hoy')->assertOk()->json('data');
        $filas = collect($data['filas']);

        $this->assertEqualsWithDelta(800.0, $data['resumen']['monto'], 0.001);
        $this->assertEqualsWithDelta(800.0, $filas->sum('monto_atendido'), 0.001);
        $this->assertEqualsWithDelta(800.0, $filas->sum('monto_cobrado'), 0.001);
    }

    public function test_un_pago_dividido_no_cuenta_la_mesa_dos_veces(): void
    {
        // Dos pagos sobre la MISMA orden: son dos cobros, pero una sola mesa atendida.
        Sanctum::actingAs($this->admin);
        $orden = $this->ordenAbiertaConTotal(400);
        $orden->update(['id_usuario' => $this->admin->id, 'id_mesero' => $this->ana->id]);

        foreach ([250, 150] as $parcial) {
            Pago::factory()->create([
                'id_orden' => $orden->id,
                'id_usuario' => $this->admin->id,
                'id_mesero' => $this->ana->id,
                'monto' => $parcial,
                'pagado_at' => now(),
            ]);
        }

        $filas = $this->filas();

        $this->assertSame(1, $filas['Ana']['ordenes']);
        $this->assertSame(2, $filas['Ana']['cobros']);
        $this->assertEqualsWithDelta(400.0, $filas['Ana']['monto_atendido'], 0.001);
    }

    public function test_el_operador_no_ve_el_reporte(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');

        // Comparar el desempeño entre compañeros es información de gestión, no de turno.
        Sanctum::actingAs($operador);
        $this->getJson('/api/v1/reportes/ventas-por-mesero?preset=hoy')->assertForbidden();
    }
}
