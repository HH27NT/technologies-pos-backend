<?php

namespace Database\Factories;

use App\Models\DetalleOrden;
use App\Models\Orden;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DetalleOrden>
 *
 * Renglón `activo` con enviado=false por defecto. El subtotal se congela como
 * cantidad × precio_unitario (descuento_item = 0 en S7). No define id_establecimiento
 * (lo impone el TenantContext). Añadida en el Sprint 7 (Órdenes).
 */
class DetalleOrdenFactory extends Factory
{
    protected $model = DetalleOrden::class;

    public function definition(): array
    {
        $cantidad = fake()->randomFloat(3, 1, 5);
        $precio = fake()->randomFloat(2, 10, 300);

        return [
            'id_orden' => Orden::factory(),
            'id_producto' => Producto::factory(),
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'descuento_item' => 0,
            'subtotal' => round($cantidad * $precio, 2),
            'enviado' => false,
            'estado_item' => 'activo',
        ];
    }

    /** Renglón ya enviado a comanda (no modificable). */
    public function enviado(): static
    {
        return $this->state(fn () => ['enviado' => true]);
    }

    /** Renglón cancelado (no cuenta en el totalizador). */
    public function cancelado(): static
    {
        return $this->state(fn () => ['estado_item' => 'cancelado', 'cancelado_at' => now()]);
    }
}
