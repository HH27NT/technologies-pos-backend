<?php

namespace Tests\Feature\Catalogo;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class MesaTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('mesa');
        Sanctum::actingAs($this->admin);
    }

    public function test_admin_crea_mesa(): void
    {
        $this->postJson('/api/v1/mesas', ['numero' => 1, 'nombre' => 'Terraza 1', 'capacidad' => 4])
            ->assertCreated()
            ->assertJsonPath('data.numero', 1)
            ->assertJsonPath('data.estado', 'libre');

        $this->assertDatabaseHas('mesas', [
            'id_establecimiento' => $this->establecimiento->id, 'numero' => 1,
        ]);
    }

    public function test_numero_unico_por_tenant(): void
    {
        $this->postJson('/api/v1/mesas', ['numero' => 5])->assertCreated();
        $this->postJson('/api/v1/mesas', ['numero' => 5])->assertStatus(422);
    }

    public function test_nombre_unico_por_tenant(): void
    {
        $this->postJson('/api/v1/mesas', ['numero' => 1, 'nombre' => 'Terraza 1'])->assertCreated();
        $this->postJson('/api/v1/mesas', ['numero' => 2, 'nombre' => 'Terraza 1'])->assertStatus(422);
    }

    public function test_mesas_sin_nombre_no_chocan_entre_si(): void
    {
        // 'nombre' es opcional: dos mesas sin nombre no deben rechazarse entre sí.
        $this->postJson('/api/v1/mesas', ['numero' => 1])->assertCreated();
        $this->postJson('/api/v1/mesas', ['numero' => 2])->assertCreated();
    }

    public function test_numero_se_autoasigna_si_no_se_manda(): void
    {
        $this->postJson('/api/v1/mesas', ['nombre' => 'Barra'])
            ->assertCreated()
            ->assertJsonPath('data.numero', 1);

        $this->postJson('/api/v1/mesas', ['nombre' => 'Terraza'])
            ->assertCreated()
            ->assertJsonPath('data.numero', 2);

        // El autoasignado no choca con uno explícito ya usado.
        $this->postJson('/api/v1/mesas', ['numero' => 3, 'nombre' => 'Salón'])->assertCreated();
        $this->postJson('/api/v1/mesas', ['nombre' => 'Jardín'])
            ->assertCreated()
            ->assertJsonPath('data.numero', 4);
    }

    public function test_mismo_numero_permitido_en_otro_tenant(): void
    {
        $this->postJson('/api/v1/mesas', ['numero' => 7])->assertCreated();

        ['admin' => $otro] = $this->nuevoTenant('mesa2');
        Sanctum::actingAs($otro);
        $this->postJson('/api/v1/mesas', ['numero' => 7])->assertCreated();
    }

    public function test_desactivar_mesa(): void
    {
        $id = $this->postJson('/api/v1/mesas', ['numero' => 9])->json('data.id');

        $this->patchJson("/api/v1/mesas/{$id}/activar", ['activa' => false])
            ->assertOk()->assertJsonPath('data.activa', false);
    }

    public function test_operador_no_gestiona_mesas(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        $this->postJson('/api/v1/mesas', ['numero' => 99])->assertStatus(403);
    }

    /**
     * El operador SÍ puede LISTAR mesas (mesas.ver): el POS las necesita para elegir
     * dónde abrir la orden. Gestionarlas sigue vedado (ver test anterior).
     */
    public function test_operador_lista_mesas(): void
    {
        $this->postJson('/api/v1/mesas', ['numero' => 3, 'nombre' => 'Barra'])->assertCreated();

        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        $this->getJson('/api/v1/mesas')
            ->assertOk()
            ->assertJsonPath('data.0.numero', 3);
    }
}
