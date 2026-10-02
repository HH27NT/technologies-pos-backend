<?php

namespace Tests\Feature\Auth;

use App\Models\Establecimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * P16 · La longitud mínima de contraseña es configurable
 * (config('pos.password.min_length')), sin bloqueo por intentos fallidos.
 */
class PoliticaPasswordTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('pwd');
    }

    public function test_respeta_la_longitud_minima_por_defecto(): void
    {
        Sanctum::actingAs($this->admin);

        // 7 caracteres < 8 por defecto → rechazado.
        $this->postJson('/api/v1/usuarios', [
            'nombre' => 'Corto',
            'email' => 'corto@pwd.test',
            'password' => 'abc1234',
            'rol' => 'operador',
        ])->assertStatus(422);
    }

    public function test_la_longitud_minima_es_configurable(): void
    {
        config(['pos.password.min_length' => 12]);
        Sanctum::actingAs($this->admin);

        // 10 caracteres: válido con la política por defecto, rechazado con min 12.
        $this->postJson('/api/v1/usuarios', [
            'nombre' => 'Diez',
            'email' => 'diez@pwd.test',
            'password' => 'abcd123456',
            'rol' => 'operador',
        ])->assertStatus(422);

        // 12 caracteres: aceptado bajo la nueva política.
        $this->postJson('/api/v1/usuarios', [
            'nombre' => 'Doce',
            'email' => 'doce@pwd.test',
            'password' => 'abcd12345678',
            'rol' => 'operador',
        ])->assertCreated();
    }
}
