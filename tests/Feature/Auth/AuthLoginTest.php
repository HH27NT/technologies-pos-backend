<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_login_super_admin_devuelve_token(): void
    {
        $respuesta = $this->postJson('/api/v1/auth/login', [
            'login' => 'super@pos.local',
            'password' => 'password',
        ]);

        $respuesta->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.usuario.es_super_admin', true);

        $this->assertNotEmpty($respuesta->json('data.token'));
    }

    public function test_credenciales_invalidas_responden_401(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'login' => 'super@pos.local',
            'password' => 'incorrecta',
        ])->assertStatus(401)->assertJsonPath('success', false);
    }

    public function test_usuario_inactivo_no_inicia_sesion(): void
    {
        ['admin' => $admin] = $this->nuevoTenant();
        $admin->activo = false;
        $admin->save();

        $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password123',
        ])->assertStatus(403);
    }

    public function test_establecimiento_inactivo_bloquea_login(): void
    {
        ['establecimiento' => $est, 'admin' => $admin] = $this->nuevoTenant();
        $est->activo = false;
        $est->save();

        $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password123',
        ])->assertStatus(423);
    }

    public function test_logout_revoca_el_token(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => 'super@pos.local',
            'password' => 'password',
        ])->json('data.token');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        // El token queda revocado (borrado) en BD: el acceso ya no es válido.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_me_requiere_autenticacion(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}
