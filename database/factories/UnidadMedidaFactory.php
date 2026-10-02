<?php

namespace Database\Factories;

use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnidadMedida>
 *
 * No define id_establecimiento: para una unidad GLOBAL pásalo explícito como null;
 * para una unidad PROPIA fija el TenantContext antes de create() (autollenado).
 */
class UnidadMedidaFactory extends Factory
{
    protected $model = UnidadMedida::class;

    public function definition(): array
    {
        return [
            'nombre' => ucfirst(fake()->unique()->word()),
            'abreviacion' => fake()->lexify('???'),
        ];
    }

    /** Estado explícito de unidad predefinida global (id_establecimiento NULL). */
    public function global(): static
    {
        return $this->state(fn () => ['id_establecimiento' => null]);
    }
}
