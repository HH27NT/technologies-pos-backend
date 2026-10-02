<?php

namespace App\Domain\Autorizaciones;

/**
 * M14 · Tipos de operación sensible que pasan por el flujo de dos niveles (matriz Fase 7,
 * regla global 21). Fuente única del literal de `autorizaciones.tipo`, la `entidad`
 * polimórfica del sujeto y, para inventario, el tipo de movimiento destino.
 */
enum TipoAutorizacion: string
{
    case CancelarItem = 'cancelar_item';
    case AnularOrden = 'anular_orden';
    case EntradaStock = 'entrada_stock';
    case AjusteStock = 'ajuste_stock';

    /** Entidad (referencia polimórfica) del sujeto afectado por la solicitud. */
    public function entidad(): string
    {
        return match ($this) {
            self::CancelarItem => 'detalle_orden',
            self::AnularOrden => 'ordenes',
            self::EntradaStock, self::AjusteStock => 'insumos',
        };
    }

    /** Una solicitud de inventario (entrada/ajuste) crea un movimiento al aprobarse. */
    public function esInventario(): bool
    {
        return $this === self::EntradaStock || $this === self::AjusteStock;
    }

    /**
     * Permiso spatie de la operación (§8). Es el "gate" del override: el AUTORIZADOR debe
     * tenerlo. Coincide con el permiso directo que exigen la OrdenPolicy / la
     * MovimientoInventarioPolicy en la ruta sin override.
     */
    public function permiso(): string
    {
        return match ($this) {
            self::CancelarItem => 'ordenes.cancelar_item',
            self::AnularOrden => 'ordenes.anular',
            self::EntradaStock => 'inventario.entrada',
            self::AjusteStock => 'inventario.ajustar',
        };
    }

    /**
     * Permisos que habilitan a un usuario como AUTORIZADOR (de cualquier tipo). Quien no
     * tenga ninguno no puede autorizar nada y por tanto tampoco fijar PIN (M14.1).
     *
     * @return list<string>
     */
    public static function permisosAutorizador(): array
    {
        return array_map(fn (self $tipo) => $tipo->permiso(), self::cases());
    }

    /** Tipo de movimiento de inventario destino (App\Domain\Inventario\TipoMovimiento). */
    public function tipoMovimiento(): ?string
    {
        return match ($this) {
            self::EntradaStock => 'entrada',
            self::AjusteStock => 'ajuste',
            default => null,
        };
    }
}
