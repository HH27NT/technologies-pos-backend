<?php

namespace Tests\Feature\Ordenes;

use App\Models\ConfiguracionEstablecimiento;
use App\Models\Establecimiento;
use App\Models\MeseroPin;
use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * PIN de mesero · terminal compartida.
 *
 * Lo que se protege aquí no es el CRUD del PIN, sino las dos promesas del bloque:
 *
 * 1. **El establecimiento con dispositivo por mesero no se entera de nada.** Su atribución
 *    sigue siendo `id_usuario` y `id_mesero` viaja nulo.
 * 2. **La atribución no se puede falsificar.** El id del mesero jamás viaja desde el cliente:
 *    se deriva de un token opaco que solo se obtiene tecleando el PIN correcto.
 *
 * Y la línea roja del diseño: este PIN **no autoriza nada**.
 */
class MeseroPinTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $mesero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('pinmesero');

        $this->mesero = $this->crearUsuarioEnTenant($this->establecimiento->id, 'mesero', [
            'nombre' => 'Ana Mesera',
        ]);

        // Después de crear al mesero: `crearUsuarioEnTenant` termina olvidando el contexto, y
        // las factories de los helpers (caja, producto, orden) lo necesitan puesto.
        $this->enContextoDe($this->establecimiento->id);
    }

    /**
     * Activa el modo terminal compartida en la configuración del tenant.
     *
     * Acotado a ESTE establecimiento a propósito: un `update()` a toda la tabla dejaría
     * a todos los tenants con el modo encendido y escondería que el modo se resolviera
     * desde la configuración de otro (que es justo lo que pasaba —
     * `ConfiguracionEstablecimiento` no lleva `BelongsToTenant`).
     */
    private function activarTerminalCompartida(): void
    {
        ConfiguracionEstablecimiento::where('id_establecimiento', $this->establecimiento->id)
            ->update(['terminal_compartida' => true]);
    }

    /** Fija el PIN del mesero como lo haría el admin, y devuelve el token de sesión efímera. */
    private function identificarse(string $pin = '482913'): string
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/usuarios/{$this->mesero->id}/mesero-pin", ['pin' => $pin])->assertOk();

        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/v1/terminal/identificar', ['pin' => $pin])
            ->assertOk()
            ->json('data.token');
    }

    public function test_el_admin_fija_el_pin_del_mesero_y_el_valor_nunca_se_devuelve(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/usuarios/{$this->mesero->id}/mesero-pin", ['pin' => '482913'])
            ->assertOk()
            ->assertJsonPath('data.configurado', true)
            ->assertJsonMissing(['pin' => '482913']);

        // Persistido con hash, nunca en claro.
        $registro = MeseroPin::where('id_usuario', $this->mesero->id)->first();
        $this->assertNotNull($registro);
        $this->assertNotSame('482913', $registro->pin_hash);
        $this->assertTrue($registro->verifica('482913'));
    }

    public function test_sin_terminal_compartida_no_se_puede_identificar(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/usuarios/{$this->mesero->id}/mesero-pin", ['pin' => '482913'])->assertOk();

        // El modo está apagado (default): el flujo entero no aplica.
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/terminal/identificar', ['pin' => '482913'])
            ->assertStatus(422);
    }

    public function test_el_modo_se_lee_de_la_configuracion_del_propio_tenant(): void
    {
        // Un establecimiento vecino con el modo ENCENDIDO; el nuestro sigue apagado.
        ['establecimiento' => $vecino, 'admin' => $adminVecino] = $this->nuevoTenant('vecino');
        // `withoutGlobalScopes` a propósito: el contexto del test es el tenant principal y la
        // tabla ya usa BelongsToTenant, así que sin esto la escritura se acota y no toca ninguna
        // fila. Es montaje cruzado deliberado, el único caso donde saltarse el scope es correcto.
        ConfiguracionEstablecimiento::withoutGlobalScopes()
            ->where('id_establecimiento', $vecino->id)
            ->update(['terminal_compartida' => true, 'bloqueo_terminal_segundos' => 45]);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/terminal/modo')
            ->assertOk()
            ->assertJsonPath('data.terminal_compartida', false)
            ->assertJsonPath('data.bloqueo_segundos', 120);

        // Y el vecino ve el suyo, no el nuestro.
        Sanctum::actingAs($adminVecino);
        $this->getJson('/api/v1/terminal/modo')
            ->assertOk()
            ->assertJsonPath('data.terminal_compartida', true)
            ->assertJsonPath('data.bloqueo_segundos', 45);
    }

    public function test_el_pin_correcto_identifica_al_mesero_sin_iniciar_sesion(): void
    {
        $this->activarTerminalCompartida();
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/usuarios/{$this->mesero->id}/mesero-pin", ['pin' => '482913'])->assertOk();

        Sanctum::actingAs($this->admin);
        $respuesta = $this->postJson('/api/v1/terminal/identificar', ['pin' => '482913'])
            ->assertOk()
            ->assertJsonPath('data.id', $this->mesero->id)
            ->assertJsonPath('data.nombre', 'Ana Mesera');

        // Devuelve token de firma, NO un token de sesión: la cuenta autenticada no cambia.
        $this->assertNotEmpty($respuesta->json('data.token'));
        $this->assertSame($this->admin->id, auth()->id());
    }

    public function test_pin_incorrecto_no_revela_si_existe(): void
    {
        $this->activarTerminalCompartida();
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/usuarios/{$this->mesero->id}/mesero-pin", ['pin' => '482913'])->assertOk();

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/terminal/identificar', ['pin' => '999999'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'PIN inválido.');
    }

    public function test_la_orden_queda_firmada_por_quien_tecleo_el_pin(): void
    {
        $this->activarTerminalCompartida();
        $this->abrirCaja($this->admin);
        $token = $this->identificarse();

        Sanctum::actingAs($this->admin);
        $respuesta = $this->postJson('/api/v1/ordenes', [
            'id_tipo_orden' => $this->tipoOrdenId('barra'),
            'mesero_token' => $token,
        ])->assertCreated();

        $orden = Orden::find($respuesta->json('data.id'));

        // La cuenta de la terminal abrió la orden…
        $this->assertSame($this->admin->id, $orden->id_usuario);
        // …pero la venta es de Ana.
        $this->assertSame($this->mesero->id, $orden->id_mesero);
        $respuesta->assertJsonPath('data.id_mesero_efectivo', $this->mesero->id);
    }

    public function test_el_mesero_firmante_viaja_en_todas_las_lecturas_de_la_orden(): void
    {
        $this->activarTerminalCompartida();
        $this->abrirCaja($this->admin);
        $token = $this->identificarse();

        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/ordenes', [
            'id_tipo_orden' => $this->tipoOrdenId('barra'),
            'mesero_token' => $token,
        ])->assertCreated()->json('data.id');

        // El POS pinta "Atiende: X" con `mesero`. Si una respuesta lo omite, el front no
        // puede distinguir "no hay firmante" de "no vino cargado" y termina enseñando la
        // cuenta de la tablet: la venta quedaría atribuida a quien no fue.
        $this->getJson("/api/v1/ordenes/{$id}")
            ->assertOk()
            ->assertJsonPath('data.mesero.nombre', 'Ana Mesera');

        $this->getJson('/api/v1/ordenes')
            ->assertOk()
            ->assertJsonPath('data.0.mesero.nombre', 'Ana Mesera');

        // Y sobrevive a una escritura posterior sobre la misma orden.
        $this->postJson("/api/v1/ordenes/{$id}/items", [
            'id_producto' => $this->crearProducto()->id,
            'cantidad' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.mesero.nombre', 'Ana Mesera');
    }

    public function test_no_se_puede_falsificar_la_atribucion_mandando_un_id(): void
    {
        $this->activarTerminalCompartida();
        $this->abrirCaja($this->admin);

        Sanctum::actingAs($this->admin);
        $respuesta = $this->postJson('/api/v1/ordenes', [
            'id_tipo_orden' => $this->tipoOrdenId('barra'),
            // Sin haber tecleado ningún PIN: intento de atribuir la venta a Ana a mano.
            'id_mesero' => $this->mesero->id,
        ])->assertCreated();

        $orden = Orden::find($respuesta->json('data.id'));

        // El id del cuerpo se ignora por completo: la firma solo nace de un token válido.
        $this->assertNull($orden->id_mesero);
    }

    public function test_un_token_invalido_deja_la_orden_sin_firma_en_vez_de_fallar(): void
    {
        $this->activarTerminalCompartida();
        $this->abrirCaja($this->admin);

        Sanctum::actingAs($this->admin);
        $respuesta = $this->postJson('/api/v1/ordenes', [
            'id_tipo_orden' => $this->tipoOrdenId('barra'),
            'mesero_token' => 'token-expirado-o-inventado',
        ])->assertCreated();

        $this->assertNull(Orden::find($respuesta->json('data.id'))->id_mesero);
    }

    public function test_con_dispositivo_por_mesero_la_atribucion_sigue_siendo_la_cuenta(): void
    {
        // Modo normal: terminal_compartida apagado, sin PIN de por medio.
        $this->abrirCaja($this->mesero);

        Sanctum::actingAs($this->mesero);
        $respuesta = $this->postJson('/api/v1/ordenes', [
            'id_tipo_orden' => $this->tipoOrdenId('barra'),
        ])->assertCreated();

        $orden = Orden::find($respuesta->json('data.id'));

        $this->assertNull($orden->id_mesero);
        $this->assertSame($this->mesero->id, $orden->id_usuario);
        // La regla COALESCE da la misma respuesta en los dos escenarios: esa es la promesa.
        $respuesta->assertJsonPath('data.id_mesero_efectivo', $this->mesero->id);
    }

    public function test_el_pin_de_mesero_no_sirve_para_autorizar(): void
    {
        $this->activarTerminalCompartida();
        $this->abrirCaja($this->admin);

        // Ana tiene PIN de mesero, pero ningún PIN de autorización.
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/usuarios/{$this->mesero->id}/mesero-pin", ['pin' => '482913'])->assertOk();

        $producto = $this->crearProducto();
        $orden = $this->ordenAbiertaConProducto($producto);
        $item = $orden->detalles()->first();

        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        Sanctum::actingAs($operador);

        // Intento de override tecleando el PIN de mesero: no resuelve a ningún autorizador.
        $this->patchJson("/api/v1/ordenes/{$orden->id}/items/{$item->id}/cancelar", [
            'motivo' => 'Prueba de línea roja',
            'autorizacion_pin' => '482913',
        ])->assertStatus(422);

        $this->assertNotSame('cancelado', $item->fresh()->estado);
    }

    public function test_el_pin_de_un_mesero_inactivo_queda_inerte(): void
    {
        $this->activarTerminalCompartida();
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/usuarios/{$this->mesero->id}/mesero-pin", ['pin' => '482913'])->assertOk();

        $this->mesero->update(['activo' => false]);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/terminal/identificar', ['pin' => '482913'])
            ->assertStatus(422);
    }

    public function test_dos_meseros_no_pueden_compartir_pin(): void
    {
        $otro = $this->crearUsuarioEnTenant($this->establecimiento->id, 'mesero');

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/usuarios/{$this->mesero->id}/mesero-pin", ['pin' => '482913'])->assertOk();

        // Si dos personas comparten PIN es imposible saber a quién atribuir la venta.
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/usuarios/{$otro->id}/mesero-pin", ['pin' => '482913'])
            ->assertStatus(422);
    }
}
