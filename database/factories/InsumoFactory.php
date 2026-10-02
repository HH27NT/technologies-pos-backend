<?php

namespace Database\Factories;

use App\Models\Insumo;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Insumo>
 *
 * stock_actual nace en 0: la existencia se carga con movimientos (ledger, D2).
 * id_unidad_medida se resuelve con una unidad del propio tenant; id_proveedor
 * queda nulo por defecto. No define id_establecimiento (lo impone el TenantContext).
 */
class InsumoFactory extends Factory
{
    protected $model = Insumo::class;

    public function definition(): array
    {
        return [
            'id_unidad_medida' => UnidadMedida::factory(),
            'id_proveedor' => null,
            'nombre' => ucfirst(fake()->unique()->words(2, true)),
            'stock_actual' => 0,
            'stock_minimo' => fake()->randomFloat(3, 1, 10),
            'costo_unitario' => fake()->randomFloat(2, 1, 100),
            'activo' => true,
        ];
    }

    /** Insumo con existencia inicial fija (cache; en pruebas que no ejercen el ledger). */
    public function conStock(float $stock): static
    {
        return $this->state(fn () => ['stock_actual' => $stock]);
    }
}
