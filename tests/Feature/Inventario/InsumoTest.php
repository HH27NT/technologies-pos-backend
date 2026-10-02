<?php

namespace Tests\Feature\Inventario;

use App\Models\Establecimiento;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class InsumoTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private int $idUnidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('ins');
        // Una unidad global del seed sirve como unidad del insumo (P4).
        $this->idUnidad = UnidadMedida::whereNull('id_establecimiento')->firstOrFail()->id;
    }

    public function test_admin_crea_insumo_con_stock_en_cero(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/insumos', [
            'nombre' => 'Limón', 'id_unidad_medida' => $this->idUnidad, 'stock_minimo' => 5,
        ])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Limón')
            ->assertJsonPath('data.stock_actual', '0.000');

        $this->assertDatabaseHas('insumos', [
            'id_establecimiento' => $this->establecimiento->id, 'nombre' => 'Limón', 'stock_actual' => 0,
        ]);
    }

    public function test_stock_inicial_registra_una_entrada_al_crear(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/insumos', [
            'nombre' => 'Ron blanco', 'id_unidad_medida' => $this->idUnidad,
            'costo_unitario' => 250, 'stock_inicial' => 12,
        ])
            ->assertCreated()
            ->assertJsonPath('data.stock_actual', '12.000')
            ->json('data.id');

        // El atajo es un movimiento real (D2), no un campo del insumo: queda en el kardex.
        $this->getJson("/api/v1/insumos/{$id}/kardex")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipo', 'entrada')
            ->assertJsonPath('data.0.cantidad', '12.000')
            ->assertJsonPath('data.0.costo_unitario', '250.00')
            ->assertJsonPath('data.0.stock_resultante', '12.000');
    }

    public function test_sin_stock_inicial_el_insumo_nace_en_cero_sin_movimientos(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/insumos', ['nombre' => 'Vodka', 'id_unidad_medida' => $this->idUnidad])
            ->assertCreated()->json('data.id');

        $this->getJson("/api/v1/insumos/{$id}/kardex")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_stock_inicial_se_rechaza_al_editar(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/insumos', ['nombre' => 'Ginebra', 'id_unidad_medida' => $this->idUnidad])
            ->json('data.id');

        $this->putJson("/api/v1/insumos/{$id}", ['nombre' => 'Ginebra premium', 'stock_inicial' => 10])
            ->assertStatus(422);
    }

    public function test_el_crud_no_modifica_stock_actual(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/insumos', ['nombre' => 'Sal', 'id_unidad_medida' => $this->idUnidad])->json('data.id');

        // Aunque el cliente intente forzar stock_actual, el servicio lo ignora (D2).
        $this->putJson("/api/v1/insumos/{$id}", ['nombre' => 'Sal fina', 'stock_actual' => 500])
            ->assertOk()->assertJsonPath('data.stock_actual', '0.000');

        $this->assertDatabaseHas('insumos', ['id' => $id, 'stock_actual' => 0]);
    }

    public function test_unidad_inexistente_es_rechazada(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/insumos', ['nombre' => 'X', 'id_unidad_medida' => 999999])
            ->assertStatus(422);
    }

    public function test_kardex_lista_los_movimientos_del_insumo(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/insumos', ['nombre' => 'Hielo', 'id_unidad_medida' => $this->idUnidad])->json('data.id');
        $this->postJson('/api/v1/movimientos', ['id_insumo' => $id, 'tipo' => 'entrada', 'cantidad' => 40])->assertCreated();

        $this->getJson("/api/v1/insumos/{$id}/kardex")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipo', 'entrada')
            ->assertJsonPath('data.0.stock_resultante', '40.000');
    }

    public function test_operador_no_gestiona_insumos(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        $this->postJson('/api/v1/insumos', ['nombre' => 'Z', 'id_unidad_medida' => $this->idUnidad])->assertStatus(403);
    }

    public function test_filtro_stock_bajo_solo_devuelve_insumos_en_o_bajo_el_minimo(): void
    {
        Sanctum::actingAs($this->admin);

        // Bajo: stock 0 <= mínimo 5 (sin movimientos). Surtido: 100 > mínimo 5.
        $bajo = $this->postJson('/api/v1/insumos', ['nombre' => 'Naranja', 'id_unidad_medida' => $this->idUnidad, 'stock_minimo' => 5])->json('data.id');
        $surtido = $this->postJson('/api/v1/insumos', ['nombre' => 'Manzana', 'id_unidad_medida' => $this->idUnidad, 'stock_minimo' => 5])->json('data.id');
        $this->postJson('/api/v1/movimientos', ['id_insumo' => $surtido, 'tipo' => 'entrada', 'cantidad' => 100])->assertCreated();

        $this->getJson('/api/v1/insumos?stock_bajo=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bajo);
    }

    /**
     * Búsqueda de texto del índice (parámetro "buscar"). Nace del armador de recetas:
     * pedía una sola página de insumos y un almacén más grande dejaba insumos
     * inalcanzables sin avisar. Se resuelve en el servidor porque filtrar la página
     * visible en el cliente esconde justo lo que se está buscando.
     */
    public function test_busca_insumos_por_nombre_sin_importar_mayusculas(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/insumos', ['nombre' => 'Tequila blanco', 'id_unidad_medida' => $this->idUnidad]);
        $this->postJson('/api/v1/insumos', ['nombre' => 'Jarabe natural', 'id_unidad_medida' => $this->idUnidad]);

        // En PostgreSQL LIKE distingue mayúsculas; este caso es el que lo delata.
        $this->getJson('/api/v1/insumos?buscar=TEQUILA')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Tequila blanco');
    }

    public function test_la_busqueda_de_insumos_no_interpreta_comodines(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/insumos', ['nombre' => 'Tequila blanco', 'id_unidad_medida' => $this->idUnidad]);
        $this->postJson('/api/v1/insumos', ['nombre' => 'Jarabe 50% azúcar', 'id_unidad_medida' => $this->idUnidad]);

        // Sin escapar, "%" sería el comodín y devolvería el almacén entero.
        $this->getJson('/api/v1/insumos?buscar=%25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Jarabe 50% azúcar');
    }

    /**
     * La combinación es la que usa el armador de recetas: busca "sal" pero los insumos
     * de consumo no deben aparecer, o alguien acabaría inventando una cantidad.
     */
    public function test_la_busqueda_no_se_escapa_del_filtro_de_tipo(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/insumos', ['nombre' => 'Sal de mar', 'id_unidad_medida' => $this->idUnidad, 'tipo' => 'controlado']);
        $this->postJson('/api/v1/insumos', ['nombre' => 'Sal de gusano', 'id_unidad_medida' => $this->idUnidad, 'tipo' => 'consumo']);

        $this->getJson('/api/v1/insumos?tipo=controlado&buscar=sal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Sal de mar');
    }
}
