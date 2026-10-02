<?php

namespace Tests\Feature\Establecimientos;

use App\Models\ConfiguracionEstablecimiento;
use App\Models\Establecimiento;
use App\Models\Usuario;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class ConfiguracionTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('cfg');
    }

    public function test_admin_consulta_la_configuracion_de_su_tenant(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/configuracion')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre_comercial', 'Bar cfg');
    }

    public function test_admin_actualiza_la_configuracion(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/configuracion', [
            'nombre_comercial' => 'Bar Renovado',
            'direccion_ticket' => 'Calle 123',
            'impresion_automatica' => true,
            'aplica_impuesto' => true,
            'tasa_impuesto' => 16,
        ])->assertOk()->assertJsonPath('data.nombre_comercial', 'Bar Renovado');

        $this->assertDatabaseHas('configuracion_establecimiento', [
            'id_establecimiento' => $this->establecimiento->id,
            'nombre_comercial' => 'Bar Renovado',
            'aplica_impuesto' => true,
            'tasa_impuesto' => 16,
        ]);

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'configuracion.actualizada',
            'entidad' => 'configuracion_establecimiento',
            'id_establecimiento' => $this->establecimiento->id,
        ]);
    }

    public function test_impuesto_activo_sin_tasa_devuelve_422(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/configuracion', [
            'aplica_impuesto' => true,
        ])->assertStatus(422);
    }

    public function test_tasa_fuera_de_rango_devuelve_422(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/configuracion', [
            'aplica_impuesto' => true,
            'tasa_impuesto' => 150,
        ])->assertStatus(422);
    }

    public function test_la_configuracion_expone_el_modo_terminal_compartida_apagado_por_defecto(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/configuracion')
            ->assertOk()
            ->assertJsonPath('data.terminal_compartida', false)
            ->assertJsonPath('data.bloqueo_terminal_segundos', 120);
    }

    public function test_admin_activa_la_terminal_compartida_y_ajusta_el_bloqueo(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/configuracion', [
            'terminal_compartida' => true,
            'bloqueo_terminal_segundos' => 300,
        ])
            ->assertOk()
            ->assertJsonPath('data.terminal_compartida', true)
            ->assertJsonPath('data.bloqueo_terminal_segundos', 300);

        $this->assertDatabaseHas('configuracion_establecimiento', [
            'id_establecimiento' => $this->establecimiento->id,
            'terminal_compartida' => true,
            'bloqueo_terminal_segundos' => 300,
        ]);
    }

    public function test_bloqueo_de_terminal_fuera_de_rango_devuelve_422(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/configuracion', ['bloqueo_terminal_segundos' => 5])
            ->assertStatus(422);

        $this->putJson('/api/v1/configuracion', ['bloqueo_terminal_segundos' => 7200])
            ->assertStatus(422);
    }

    public function test_operador_no_puede_consultar_ni_editar(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        $this->getJson('/api/v1/configuracion')->assertStatus(403);
        $this->putJson('/api/v1/configuracion', ['nombre_comercial' => 'X'])->assertStatus(403);
    }

    public function test_aislamiento_cada_admin_edita_solo_su_configuracion(): void
    {
        ['establecimiento' => $otro] = $this->nuevoTenant('otro');

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/configuracion', ['nombre_comercial' => 'Solo Mio'])->assertOk();

        // El establecimiento ajeno conserva su nombre comercial por defecto.
        $this->assertDatabaseHas('configuracion_establecimiento', [
            'id_establecimiento' => $otro->id,
            'nombre_comercial' => 'Bar otro',
        ]);
    }

    public function test_una_consulta_sin_where_queda_acotada_al_tenant_del_contexto(): void
    {
        // Era la única tabla operativa sin `BelongsToTenant`, y eso ya costó un bug real: en el
        // bloque del PIN de mesero un `first()` le sirvió a un local el modo de terminal
        // compartida de otro. Lo que se blinda aquí es el DEFAULT: una consulta que se olvide
        // del `where` ya no puede alcanzar la fila del vecino.
        ['establecimiento' => $vecino] = $this->nuevoTenant('vecino');
        ConfiguracionEstablecimiento::withoutGlobalScopes()
            ->where('id_establecimiento', $vecino->id)
            ->update(['nombre_comercial' => 'Bar del vecino']);

        app(TenantContext::class)->set($this->establecimiento->id);

        $sinWhere = ConfiguracionEstablecimiento::first();

        $this->assertSame($this->establecimiento->id, (int) $sinWhere->id_establecimiento);
        $this->assertNotSame('Bar del vecino', $sinWhere->nombre_comercial);
        $this->assertNull(
            ConfiguracionEstablecimiento::where('id_establecimiento', $vecino->id)->first(),
            'La fila del vecino debe quedar fuera de alcance incluso pidiéndola por su id.'
        );
    }

    public function test_sin_contexto_el_scope_no_filtra_por_eso_los_workers_siguen_acotando(): void
    {
        // El límite del trait, escrito como hecho ejecutable: `TenantScope` no aplica filtro
        // cuando no hay contexto (workers de cola, consola, seeders). Por eso el ticket, la
        // comanda y el recálculo de totales siguen filtrando por `$orden->id_establecimiento`
        // en vez de confiar en el scope. Si algún día esto cambia, este test lo avisa.
        $this->nuevoTenant('vecino');
        app(TenantContext::class)->olvidar();

        $this->assertGreaterThan(1, ConfiguracionEstablecimiento::count());
    }
}
