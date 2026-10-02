<?php

namespace Database\Factories;

use App\Models\CategoriaProducto;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Producto>
 *
 * id_categoria se resuelve creando una categoría en el mismo contexto de tenant.
 * No define id_establecimiento: lo impone el TenantContext (autollenado).
 */
class ProductoFactory extends Factory
{
    protected $model = Producto::class;

    public function definition(): array
    {
        return [
            'id_categoria' => CategoriaProducto::factory(),
            'nombre' => ucfirst(fake()->unique()->words(2, true)),
            'descripcion' => fake()->optional()->sentence(),
            'precio_venta' => fake()->randomFloat(2, 10, 500),
            'costo_referencia' => fake()->randomFloat(2, 5, 300),
            'controla_inventario' => false,
            'disponible' => true,
            'sku' => fake()->optional()->bothify('SKU-####'),
        ];
    }

    /** Producto que descuenta inventario al venderse (depende de tener receta). */
    public function controlaInventario(): static
    {
        return $this->state(fn () => ['controla_inventario' => true]);
    }
}
