<?php

namespace Database\Factories;

use App\Models\Orden;
use App\Models\SesionCaja;
use App\Models\TipoOrden;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Orden>
 *
 * Orden mínima para ejercitar el arqueo de caja (Sprint 6). El módulo de Órdenes
 * (Sprint 7) la construye de verdad; aquí solo se siembra para colgar pagos.
 * No define id_establecimiento (lo impone el TenantContext).
 */
class OrdenFactory extends Factory
{
    protected $model = Orden::class;

    public function definition(): array
    {
        return [
            'id_sesion_caja' => SesionCaja::factory(),
            'id_mesa' => null,
            'id_tipo_orden' => fn () => TipoOrden::query()->value('id'),
            'id_usuario' => Usuario::factory(),
            'folio' => 'ORD-'.fake()->unique()->numerify('######'),
            'estado' => 'abierta',
            'abierta_at' => now(),
        ];
    }

    public function pagada(): static
    {
        return $this->state(fn () => ['estado' => 'pagada', 'cerrada_at' => now()]);
    }
}
