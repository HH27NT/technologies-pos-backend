<?php

namespace Database\Factories;

use App\Models\Orden;
use App\Models\Pago;
use App\Models\TipoPago;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pago>
 *
 * Pago en efectivo por defecto. Añadida en el Sprint 6 para sembrar el arqueo de
 * caja (P6); el módulo de Pagos (Sprint 8) escribe los pagos reales.
 * No define id_establecimiento (lo impone el TenantContext).
 */
class PagoFactory extends Factory
{
    protected $model = Pago::class;

    public function definition(): array
    {
        return [
            'id_orden' => Orden::factory(),
            'id_tipo_pago' => fn () => $this->tipoPagoId('efectivo'),
            'id_usuario' => Usuario::factory(),
            'monto' => fake()->randomFloat(2, 50, 500),
            'pagado_at' => now(),
        ];
    }

    public function efectivo(): static
    {
        return $this->state(fn () => ['id_tipo_pago' => $this->tipoPagoId('efectivo')]);
    }

    public function tarjeta(): static
    {
        return $this->state(fn () => ['id_tipo_pago' => $this->tipoPagoId('tarjeta')]);
    }

    private function tipoPagoId(string $nombre): int
    {
        return TipoPago::where('nombre', $nombre)->value('id');
    }
}
