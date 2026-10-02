<?php

namespace Database\Factories;

use App\Models\CategoriaProducto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoriaProducto>
 *
 * No define id_establecimiento: requiere TenantContext activo (autollenado) o que
 * se pase explícito, para poder probar el aislamiento multi-tenant.
 */
class CategoriaProductoFactory extends Factory
{
    protected $model = CategoriaProducto::class;

    public function definition(): array
    {
        return [
            'nombre' => ucfirst(fake()->unique()->word()),
            'orden_display' => fake()->numberBetween(0, 20),
            'activo' => true,
        ];
    }
}
