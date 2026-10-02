<?php

namespace App\Domain\Reportes;

use App\Models\SesionCaja;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;

/**
 * M16 · Alcance por rol de un reporte (P21). El ADMIN (`reportes.ver`) ve todo el
 * establecimiento; el OPERADOR (`reportes.ver_limitado`) solo ve SU turno: las sesiones
 * de caja que él abrió y las órdenes/pagos dentro de ellas. El acotamiento es una regla
 * de negocio y vive aquí, no en la policy. Es serializable (snapshot) para viajar al job
 * de exportación sin depender del usuario autenticado dentro del worker.
 */
final class AlcanceReporte
{
    /** @param  list<int>  $sesionesPermitidas */
    private function __construct(
        public readonly bool $limitado,
        public readonly ?int $idUsuario,
        public readonly array $sesionesPermitidas,
    ) {}

    public static function para(Usuario $usuario): self
    {
        if ($usuario->can('reportes.ver')) {
            return new self(false, null, []);
        }

        $sesiones = SesionCaja::query()
            ->where('id_usuario_apertura', $usuario->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return new self(true, (int) $usuario->id, $sesiones);
    }

    public static function desdeSnapshot(array $s): self
    {
        return new self(
            (bool) ($s['limitado'] ?? false),
            isset($s['id_usuario']) ? (int) $s['id_usuario'] : null,
            array_map('intval', $s['sesiones'] ?? []),
        );
    }

    public function snapshot(): array
    {
        return [
            'limitado' => $this->limitado,
            'id_usuario' => $this->idUsuario,
            'sesiones' => $this->sesionesPermitidas,
        ];
    }

    /** Acota una query de `ordenes` a las sesiones del operador (sin efecto para el admin). */
    public function aplicarAOrdenes(Builder $q, string $columna = 'id_sesion_caja'): Builder
    {
        if ($this->limitado) {
            $q->whereIn($columna, $this->sesionesPermitidas ?: [0]);
        }

        return $q;
    }

    /** Acota una query de `sesiones_caja` a las del operador (sin efecto para el admin). */
    public function aplicarASesiones(Builder $q): Builder
    {
        if ($this->limitado) {
            $q->where('id_usuario_apertura', $this->idUsuario ?? 0);
        }

        return $q;
    }
}
