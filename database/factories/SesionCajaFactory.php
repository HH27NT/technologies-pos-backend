<?php

namespace Database\Factories;

use App\Models\SesionCaja;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SesionCaja>
 *
 * Sesión `abierta` por defecto. No define id_establecimiento (lo impone el
 * TenantContext). Añadida en el Sprint 6 para las pruebas de caja.
 */
class SesionCajaFactory extends Factory
{
    protected $model = SesionCaja::class;

    public function definition(): array
    {
        return [
            'id_usuario_apertura' => Usuario::factory(),
            'monto_inicial' => fake()->randomFloat(2, 100, 1000),
            'estado' => 'abierta',
            'abierta_at' => now(),
        ];
    }

    /** Sesión ya cerrada con su arqueo (para el histórico). */
    public function cerrada(float $diferencia = 0): static
    {
        return $this->state(fn (array $attrs) => [
            'monto_sistema' => $attrs['monto_inicial'],
            'monto_contado' => $attrs['monto_inicial'] + $diferencia,
            'diferencia' => $diferencia,
            'motivo' => $diferencia != 0.0 ? 'ajuste de arqueo' : null,
            'estado' => 'cerrada',
            'cerrada_at' => now(),
        ]);
    }
}
