<?php

namespace Tests\Feature\Auth;

use App\Notifications\RecuperarPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M01 · POST /api/v1/auth/recuperar — solicitud del enlace de recuperación.
 */
class RecuperarPasswordTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_envia_enlace_a_usuario_activo(): void
    {
        Notification::fake();
        ['admin' => $admin] = $this->nuevoTenant('a');

        $this->postJson('/api/v1/auth/recuperar', ['email' => $admin->email])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($admin, RecuperarPasswordNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $admin->email]);
    }

    public function test_correo_inexistente_responde_generico_sin_enviar(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/recuperar', ['email' => 'noexiste@nadie.test'])
            ->assertOk()
            ->assertJsonPath('success', true);

        // Anti-enumeración: misma respuesta, pero no se envía nada.
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'noexiste@nadie.test']);
    }

    public function test_no_envia_enlace_a_usuario_inactivo(): void
    {
        Notification::fake();
        ['admin' => $admin] = $this->nuevoTenant('b');
        $admin->activo = false;
        $admin->save();

        $this->postJson('/api/v1/auth/recuperar', ['email' => $admin->email])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertNothingSent();
    }

    public function test_email_invalido_responde_422(): void
    {
        $this->postJson('/api/v1/auth/recuperar', ['email' => 'no-es-correo'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->postJson('/api/v1/auth/recuperar', [])
            ->assertStatus(422);
    }
}
