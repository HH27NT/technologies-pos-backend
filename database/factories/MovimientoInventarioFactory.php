<?php

namespace Database\Factories;

use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MovimientoInventario>
 *
 * Movimiento del ledger (append-only). Por defecto una 'entrada'. created_at lo
 * fija la BD (useCurrent). No define id_establecimiento (lo impone el TenantContext).
 */
class MovimientoInventarioFactory extends Factory
{
    protected $model = MovimientoInventario::class;

    public function definition(): array
    {
        return [
            'id_insumo' => Insumo::factory(),
            'id_usuario' => Usuario::factory(),
            'id_orden' => null,
            'id_autorizacion' => null,
            'tipo' => 'entrada',
            'cantidad' => fake()->randomFloat(3, 1, 50),
            'costo_unitario' => fake()->randomFloat(2, 1, 100),
            'stock_resultante' => null,
            'motivo' => null,
        ];
    }
}
