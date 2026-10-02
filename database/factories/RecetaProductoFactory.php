<?php

namespace Database\Factories;

use App\Models\Insumo;
use App\Models\Producto;
use App\Models\RecetaProducto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecetaProducto>
 *
 * Línea de receta (BOM). Producto e insumo se crean en el mismo contexto de tenant.
 * El par id_producto+id_insumo es único (índice + servicio).
 */
class RecetaProductoFactory extends Factory
{
    protected $model = RecetaProducto::class;

    public function definition(): array
    {
        return [
            'id_producto' => Producto::factory(),
            'id_insumo' => Insumo::factory(),
            'cantidad' => fake()->randomFloat(3, 0.1, 5),
        ];
    }
}
