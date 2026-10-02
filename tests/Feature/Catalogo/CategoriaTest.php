<?php

namespace Tests\Feature\Catalogo;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class CategoriaTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('cat');
    }

    public function test_admin_crea_y_lista_categorias(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/categorias', ['nombre' => 'Bebidas', 'orden_display' => 1])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Bebidas');

        $this->assertDatabaseHas('categorias_producto', [
            'id_establecimiento' => $this->establecimiento->id,
            'nombre' => 'Bebidas',
        ]);

        $this->getJson('/api/v1/categorias')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_actualiza_categoria(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/categorias', ['nombre' => 'Comidas'])->json('data.id');

        $this->putJson("/api/v1/categorias/{$id}", ['nombre' => 'Platillos'])
            ->assertOk()->assertJsonPath('data.nombre', 'Platillos');
    }

    public function test_desactivar_categoria_con_productos_advierte_sin_bloquear(): void
    {
        Sanctum::actingAs($this->admin);
        $idCat = $this->postJson('/api/v1/categorias', ['nombre' => 'Cervezas'])->json('data.id');
        $this->postJson('/api/v1/productos', [
            'id_categoria' => $idCat, 'nombre' => 'IPA', 'precio_venta' => 80,
        ])->assertCreated();

        $this->patchJson("/api/v1/categorias/{$idCat}/activar", ['activo' => false])
            ->assertOk()
            ->assertJsonPath('data.activo', false)
            ->assertJsonFragment(['message' => 'Categoría desactivada. Tiene productos asociados que dejarán de mostrarse en venta.']);
    }

    public function test_nombre_obligatorio(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/categorias', [])->assertStatus(422);
    }

    public function test_operador_lee_categorias_pero_no_las_gestiona(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        // Leer es parte de vender: el filtro por categoría del POS lo necesita
        // (`categorias.ver`). Escribir sigue siendo del admin (`categorias.gestionar`).
        $this->getJson('/api/v1/categorias')->assertOk();
        $this->postJson('/api/v1/categorias', ['nombre' => 'X'])->assertStatus(403);
    }
}
