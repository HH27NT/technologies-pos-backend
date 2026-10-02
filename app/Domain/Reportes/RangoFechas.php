<?php

namespace App\Domain\Reportes;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * M16 · Rango de fechas de un reporte, resuelto en la ZONA HORARIA del establecimiento
 * y convertido a UTC para filtrar los timestamps (que la BD guarda en UTC). Calcular en
 * UTC directo produciría reportes "corridos" (regla M16). El fin es EXCLUSIVO
 * `[inicio, fin)`, de modo que los filtros usan `>= inicio` y `< fin` sin solapes.
 */
final class RangoFechas
{
    /**
     * Meses en español, explícitos a propósito: la app corre en locale `en` y este es el único
     * sitio que necesita el nombre del mes. Colgarlo del traductor global haría que el rótulo de
     * un reporte dependiera de una configuración que ningún otro módulo usa.
     */
    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    private function __construct(
        public readonly CarbonImmutable $inicioUtc,
        public readonly CarbonImmutable $finUtc,
        public readonly string $zonaHoraria,
        public readonly string $preset,
    ) {}

    /**
     * Construye el rango desde la entrada del cliente: `desde`/`hasta` (Y-m-d) explícitos
     * o un `preset` (hoy/semana/mes/anio). Sin entrada → `hoy`.
     */
    public static function desde(array $input, ?string $zonaHoraria = null): self
    {
        $tz = ($zonaHoraria !== null && $zonaHoraria !== '') ? $zonaHoraria : 'UTC';

        if (! empty($input['desde']) || ! empty($input['hasta'])) {
            $desde = CarbonImmutable::parse($input['desde'] ?? $input['hasta'], $tz)->startOfDay();
            $hasta = CarbonImmutable::parse($input['hasta'] ?? $input['desde'], $tz)->startOfDay();

            return new self($desde->setTimezone('UTC'), $hasta->addDay()->setTimezone('UTC'), $tz, 'personalizado');
        }

        $hoy = CarbonImmutable::now($tz);

        [$inicio, $fin, $preset] = match ($input['preset'] ?? 'hoy') {
            'semana' => [$hoy->startOfWeek(), $hoy->startOfWeek()->addWeek(), 'semana'],
            'mes' => [$hoy->startOfMonth(), $hoy->startOfMonth()->addMonth(), 'mes'],
            'anio', 'año', 'ano' => [$hoy->startOfYear(), $hoy->startOfYear()->addYear(), 'anio'],
            default => [$hoy->startOfDay(), $hoy->startOfDay()->addDay(), 'hoy'],
        };

        return new self($inicio->setTimezone('UTC'), $fin->setTimezone('UTC'), $tz, $preset);
    }

    /** Convierte un timestamp UTC de la BD a la fecha local (Y-m-d) del establecimiento. */
    public function fechaLocal(DateTimeInterface $utc): string
    {
        return CarbonImmutable::instance($utc)->setTimezone($this->zonaHoraria)->format('Y-m-d');
    }

    /** Convierte un timestamp UTC a la fecha-hora local legible del establecimiento. */
    public function fechaHoraLocal(?DateTimeInterface $utc): ?string
    {
        return $utc === null
            ? null
            : CarbonImmutable::instance($utc)->setTimezone($this->zonaHoraria)->format('Y-m-d H:i');
    }

    public function meta(): array
    {
        return [
            'zona_horaria' => $this->zonaHoraria,
            'preset' => $this->preset,
            // Instantes UTC: el dato de máquina, con el fin EXCLUSIVO tal como se consulta.
            'inicio' => $this->inicioUtc->toIso8601String(),
            'fin' => $this->finUtc->toIso8601String(),
            // Para mostrar: en la zona del establecimiento y con el último día INCLUIDO.
            'inicio_local' => $this->primerDiaLocal()->format('Y-m-d'),
            'fin_local' => $this->ultimoDiaLocal()->format('Y-m-d'),
            'etiqueta' => $this->etiqueta(),
            'preset_etiqueta' => $this->presetEtiqueta(),
        ];
    }

    /** Primer día incluido en el rango, en la zona del establecimiento. */
    public function primerDiaLocal(): CarbonImmutable
    {
        return $this->inicioUtc->setTimezone($this->zonaHoraria);
    }

    /**
     * ÚLTIMO día incluido, en la zona del establecimiento.
     *
     * `finUtc` es exclusivo: en un reporte de "hoy" apunta a la medianoche de mañana. Enseñar
     * esa fecha en un papel hace creer que el reporte abarca un día más de los que abarca, así
     * que se retrocede un instante antes de quedarse con la fecha.
     */
    public function ultimoDiaLocal(): CarbonImmutable
    {
        return $this->finUtc->subSecond()->setTimezone($this->zonaHoraria);
    }

    /**
     * El rango en palabras, para quien lee el reporte impreso:
     * "21 de agosto de 2026" · "del 1 al 21 de agosto de 2026" ·
     * "del 28 de julio al 21 de agosto de 2026" · "del 28 de diciembre de 2025 al 3 de enero de 2026".
     *
     * Siempre en la zona del establecimiento y con el último día incluido. Lo que se imprimía
     * antes eran los dos instantes UTC en ISO 8601: ilegibles, y además corridos —un reporte de
     * "hoy" en México se leía como "del 21 a las 06:00 al 22 a las 06:00".
     */
    public function etiqueta(): string
    {
        $desde = $this->primerDiaLocal();
        $hasta = $this->ultimoDiaLocal();

        if ($desde->isSameDay($hasta)) {
            return $this->diaCompleto($desde);
        }

        // Se omite lo que ambas fechas comparten: el mes cuando coincide, y siempre el año final.
        if ($desde->year === $hasta->year && $desde->month === $hasta->month) {
            return "del {$desde->day} al ".$this->diaCompleto($hasta);
        }

        if ($desde->year === $hasta->year) {
            return 'del '.$desde->day.' de '.self::MESES[$desde->month].' al '.$this->diaCompleto($hasta);
        }

        return 'del '.$this->diaCompleto($desde).' al '.$this->diaCompleto($hasta);
    }

    /** El preset en palabras, para encabezar el reporte. */
    public function presetEtiqueta(): string
    {
        return match ($this->preset) {
            'hoy' => 'Hoy',
            'semana' => 'Esta semana',
            'mes' => 'Este mes',
            'anio' => 'Este año',
            default => 'Periodo personalizado',
        };
    }

    private function diaCompleto(CarbonImmutable $fecha): string
    {
        return $fecha->day.' de '.self::MESES[$fecha->month].' de '.$fecha->year;
    }
}
