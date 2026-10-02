<?php

namespace App\Domain\Inventario;

/**
 * M08 · Tipos de movimiento del ledger de inventario. FUENTE ÚNICA del signo por
 * tipo (R3): tanto el registro (RegistrarMovimientoService) como la reconciliación
 * (ReconciliarStockService) y la validación de entrada (RegistrarMovimientoRequest)
 * derivan su comportamiento de aquí, de modo que añadir un tipo nuevo se hace en un
 * solo lugar y el cache no puede divergir del ledger.
 *
 * Signo: entrada/ajuste suman (+1); el resto resta (-1).
 * 'ajuste' solo corrige al alza (R1): una corrección a la baja por conteo se
 * registra como 'merma', que tiene su propia auditoría de pérdida.
 * 'venta'/'salida' los origina el cobro (Sprint 8) y NO son registrables a mano.
 */
enum TipoMovimiento: string
{
    case Entrada = 'entrada';
    case Ajuste = 'ajuste';
    case Merma = 'merma';
    case Rotura = 'rotura';
    case ConsumoInterno = 'consumo_interno';
    case Venta = 'venta';
    case Salida = 'salida';

    /** +1 suma al stock, -1 resta. Único lugar donde vive el signo por tipo. */
    public function signo(): int
    {
        return match ($this) {
            self::Entrada, self::Ajuste => 1,
            self::Merma, self::Rotura, self::ConsumoInterno, self::Venta, self::Salida => -1,
        };
    }

    /** Registrable vía POST /movimientos. venta/salida los origina el cobro (Sprint 8). */
    public function esManual(): bool
    {
        return $this !== self::Venta && $this !== self::Salida;
    }

    /**
     * Exige 'motivo' escrito. Regla global 19: toda merma, rotura, ajuste y consumo
     * se registra y audita; la entrada no necesita justificación (R2).
     */
    public function requiereMotivo(): bool
    {
        return match ($this) {
            self::Ajuste, self::Merma, self::Rotura, self::ConsumoInterno => true,
            default => false,
        };
    }

    /** @return list<string> tipos registrables a mano (para Rule::in del Form Request). */
    public static function manuales(): array
    {
        return self::valoresDe(fn (self $t) => $t->esManual());
    }

    /** @return list<string> tipos que exigen motivo (para requiredIf del Form Request). */
    public static function conMotivo(): array
    {
        return self::valoresDe(fn (self $t) => $t->requiereMotivo());
    }

    /** @return list<string> tipos que suman al stock (+1). El resto resta. */
    public static function queSuman(): array
    {
        return self::valoresDe(fn (self $t) => $t->signo() === 1);
    }

    /** @param  callable(self):bool  $filtro */
    private static function valoresDe(callable $filtro): array
    {
        return array_values(array_map(
            fn (self $t) => $t->value,
            array_filter(self::cases(), $filtro)
        ));
    }
}
