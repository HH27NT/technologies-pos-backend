<?php

namespace Database\Factories;

use App\Models\Establecimiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Establecimiento>
 */
class EstablecimientoFactory extends Factory
{
    protected $model = Establecimiento::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->company(),
            'razon_social' => fake()->company().' SA de CV',
            'rfc' => strtoupper(fake()->bothify('???######???')),
            'telefono' => fake()->numerify('##########'),
            'email' => fake()->unique()->companyEmail(),
            'zona_horaria' => 'America/Mexico_City',
            'moneda' => 'MXN',
            'activo' => true,
        ];
    }
}
