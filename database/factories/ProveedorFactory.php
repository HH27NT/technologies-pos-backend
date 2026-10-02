<?php

namespace Database\Factories;

use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proveedor>
 *
 * No define id_establecimiento: requiere TenantContext activo (autollenado) o que
 * se pase explícito. Útil para probar el aislamiento multi-tenant.
 */
class ProveedorFactory extends Factory
{
    protected $model = Proveedor::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->company(),
            'telefono' => fake()->numerify('##########'),
            'email' => fake()->unique()->companyEmail(),
            'activo' => true,
        ];
    }
}
