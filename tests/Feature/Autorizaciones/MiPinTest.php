<?php

namespace Tests\Feature\Autorizaciones;

use App\Models\AutorizacionPin;
use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M14.1 · Gestión self-service del PIN de autorización (`/mi-pin`). Solo el propio
 * autorizador fija su PIN, confirmando su contraseña de login; el PIN nunca se devuelve, es
 * único dentro del establecimiento y no puede ser trivial.
 */
class MiPinTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('pin');
        $this->enContextoDe($this->establecimiento->id);
    }

    /** Body completo de `PUT /mi-pin`. */
    private function body(string $pin, string $password = 'password123'): array
    {
        return [
            'pin' => $pin,
            'pin_confirmation' => $pin,
            'password_actual' => $password,
        ];
    }

    public function test_estado_inicial_es_sin_configurar(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/mi-pin')
            ->assertOk()
            ->assertJsonPath('data.configurado', false)
            ->assertJsonPath('data.actualizado_at', null);
    }

    public function test_admin_fija_su_pin(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/mi-pin', $this->body('482913'))
            ->assertOk()
            ->assertJsonPath('data.configurado', true);

        $this->getJson('/api/v1/mi-pin')->assertJsonPath('data.configurado', true);

        // Se persiste hasheado y con lookup: el PIN en claro no aparece en la fila.
        $fila = $this->pinDe($this->admin);
        $this->assertNotSame('482913', $fila->pin_hash);
        $this->assertSame(64, strlen($fila->pin_lookup));
    }

    public function test_la_respuesta_nunca_expone_el_pin(): void
    {
        Sanctum::actingAs($this->admin);

        $respuesta = $this->putJson('/api/v1/mi-pin', $this->body('482913'))->assertOk();

        $this->assertStringNotContainsString('482913', $respuesta->getContent());
        $respuesta->assertJsonMissingPath('data.pin_hash')->assertJsonMissingPath('data.pin_lookup');
    }

    public function test_cambiar_el_pin_lo_reemplaza_sin_duplicar_filas(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/mi-pin', $this->body('482913'))->assertOk();
        $this->putJson('/api/v1/mi-pin', $this->body('735284'))->assertOk();

        $this->assertDatabaseCount('autorizacion_pins', 1);
    }

    public function test_contrasena_actual_incorrecta_rechaza_el_cambio(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/mi-pin', $this->body('482913', 'contraseña-mala'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password_actual');

        $this->assertDatabaseCount('autorizacion_pins', 0);
    }

    public function test_confirmacion_que_no_coincide_rechaza_el_cambio(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/mi-pin', [
            'pin' => '482913',
            'pin_confirmation' => '482914',
            'password_actual' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('pin');
    }

    public function test_pin_mal_formado_o_trivial_se_rechaza(): void
    {
        Sanctum::actingAs($this->admin);

        // Repetidos y secuencias son lo primero que probaría alguien con el teclado enfrente.
        foreach (['111111', '000000', '123456', '654321', '1234', '48a913'] as $pin) {
            $this->putJson('/api/v1/mi-pin', $this->body($pin))
                ->assertStatus(422)
                ->assertJsonValidationErrors('pin');
        }

        $this->assertDatabaseCount('autorizacion_pins', 0);
    }

    public function test_pin_duplicado_en_el_mismo_establecimiento_se_rechaza(): void
    {
        $otroAdmin = $this->crearUsuarioEnTenant($this->establecimiento->id, 'admin');

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/mi-pin', $this->body('482913'))->assertOk();

        // El PIN identifica al autorizador: dos personas con el mismo PIN lo harían ambiguo.
        Sanctum::actingAs($otroAdmin);
        $this->putJson('/api/v1/mi-pin', $this->body('482913'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ese PIN ya está en uso en este establecimiento. Elige otro.');
    }

    public function test_el_mismo_pin_es_valido_en_otro_establecimiento(): void
    {
        ['admin' => $adminAjeno] = $this->nuevoTenant('otro');

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/mi-pin', $this->body('482913'))->assertOk();

        // La unicidad es POR establecimiento: en otro tenant ese PIN está libre.
        $this->enContextoDe($adminAjeno->id_establecimiento);
        Sanctum::actingAs($adminAjeno);
        $this->putJson('/api/v1/mi-pin', $this->body('482913'))->assertOk();

        $this->assertDatabaseCount('autorizacion_pins', 2);
    }

    public function test_operador_sin_permisos_de_autorizador_no_puede_fijar_pin(): void
    {
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');

        Sanctum::actingAs($operador);
        $this->putJson('/api/v1/mi-pin', $this->body('482913'))->assertStatus(403);

        $this->assertDatabaseCount('autorizacion_pins', 0);
    }

    public function test_admin_elimina_su_pin(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/mi-pin', $this->body('482913'))->assertOk();

        $this->deleteJson('/api/v1/mi-pin')
            ->assertOk()
            ->assertJsonPath('data.configurado', false);

        $this->assertDatabaseCount('autorizacion_pins', 0);
    }

    private function pinDe(Usuario $usuario): AutorizacionPin
    {
        return AutorizacionPin::withoutGlobalScopes()
            ->where('id_usuario', $usuario->id)
            ->firstOrFail();
    }
}
