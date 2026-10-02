<?php

namespace App\Domain\Ordenes;

use Illuminate\Database\Eloquent\Builder;

/**
 * Quién atendió realmente una venta, con independencia de cómo opere el establecimiento.
 *
 * El SaaS soporta dos modos y **no debe tener dos ramas de lógica**:
 *
 * - **Dispositivo por mesero:** cada persona entra con su cuenta. `id_usuario` YA es el mesero
 *   y `id_mesero` viaja nulo. El tenant no configura nada.
 * - **Terminal compartida:** la cuenta es del dispositivo, así que la persona se estampa en
 *   `id_mesero` al teclear su PIN.
 *
 * La regla es una sola:
 *
 *     mesero_efectivo = COALESCE(id_mesero, id_usuario)
 *
 * Todo lo que pregunte "¿de quién es esta venta?" —el filtro "mis órdenes", el traspaso, los
 * reportes por mesero— debe pasar por aquí. Si alguien lee `id_usuario` a pelo, el modo de
 * terminal compartida le dará la cuenta del dispositivo y el número saldrá mal sin fallar,
 * que es la peor forma de estar equivocado.
 */
class MeseroEfectivo
{
    /** Expresión SQL para SELECT/GROUP BY. Portable pgsql/sqlite. */
    public const SQL = 'COALESCE(id_mesero, id_usuario)';

    /** Id de la persona que atendió, dado un registro con ambos campos. */
    public static function de(object $registro): ?int
    {
        return self::entre($registro->id_mesero ?? null, $registro->id_usuario ?? null);
    }

    /**
     * La misma regla sobre los dos valores sueltos. Existe para los JOIN, donde las
     * columnas llegan con alias (`orden_id_mesero`) y ya no hay un registro que cumpla
     * la forma de arriba. Sin esto, cada consulta reescribiría el COALESCE a mano —
     * que es justo lo que esta clase evita.
     */
    public static function entre(?int $idMesero, ?int $idUsuario): ?int
    {
        return $idMesero ?? $idUsuario;
    }

    /** Restringe la consulta a las ventas de una persona, en cualquiera de los dos modos. */
    public static function scope(Builder $consulta, int $idUsuario): Builder
    {
        return $consulta->whereRaw(self::SQL.' = ?', [$idUsuario]);
    }
}
