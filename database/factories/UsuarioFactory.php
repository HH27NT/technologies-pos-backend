<?php

namespace Database\Factories;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Usuario>
 *
 * No define id_establecimiento: si hay TenantContext activo se autollena; en su
 * ausencia queda NULL (caso super_admin). Para un usuario de tenant, fija el
 * contexto antes de create() o pasa id_establecimiento explícito.
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'username' => fake()->unique()->userName(),
            'password_hash' => 'password',
            'activo' => true,
            'id_rol' => null,
        ];
    }
}
