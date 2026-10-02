<?php

namespace Tests\Unit\Reportes;

use App\Domain\Reportes\RangoFechas;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * M16 · Los rangos se resuelven en la zona horaria del establecimiento y se convierten a
 * UTC `[inicio, fin)`. Calcular en UTC directo produciría reportes "corridos".
 */
class RangoFechasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 2026-07-01 18:00 UTC = 12:00 en America/Mexico_City (UTC-6) del mismo día.
        Carbon::setTestNow('2026-07-01T18:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hoy_se_calcula_en_la_zona_del_establecimiento(): void
    {
        $rango = RangoFechas::desde(['preset' => 'hoy'], 'America/Mexico_City');

        // Medianoche local (00:00 -06:00) = 06:00 UTC; el fin es la medianoche siguiente.
        $this->assertSame('2026-07-01T06:00:00+00:00', $rango->inicioUtc->toIso8601String());
        $this->assertSame('2026-07-02T06:00:00+00:00', $rango->finUtc->toIso8601String());
        $this->assertSame('America/Mexico_City', $rango->zonaHoraria);
    }

    public function test_sin_zona_usa_utc(): void
    {
        $rango = RangoFechas::desde(['preset' => 'hoy'], null);

        $this->assertSame('2026-07-01T00:00:00+00:00', $rango->inicioUtc->toIso8601String());
        $this->assertSame('UTC', $rango->zonaHoraria);
    }

    public function test_rango_explicito_es_fin_exclusivo(): void
    {
        $rango = RangoFechas::desde(['desde' => '2026-06-01', 'hasta' => '2026-06-30'], 'America/Mexico_City');

        // desde 00:00 local del 1; fin = 00:00 local del día SIGUIENTE al 30 (exclusivo).
        $this->assertSame('2026-06-01T06:00:00+00:00', $rango->inicioUtc->toIso8601String());
        $this->assertSame('2026-07-01T06:00:00+00:00', $rango->finUtc->toIso8601String());
        $this->assertSame('personalizado', $rango->preset);
    }

    public function test_fecha_local_convierte_desde_utc(): void
    {
        $rango = RangoFechas::desde(['preset' => 'hoy'], 'America/Mexico_City');

        // 2026-07-01 03:00 UTC = 2026-06-30 21:00 local → fecha local del día anterior.
        $this->assertSame('2026-06-30', $rango->fechaLocal(CarbonImmutable::parse('2026-07-01T03:00:00Z')));
    }

    public function test_la_etiqueta_de_un_solo_dia_no_repite_la_fecha(): void
    {
        $rango = RangoFechas::desde(['preset' => 'hoy'], 'America/Mexico_City');

        $this->assertSame('1 de julio de 2026', $rango->etiqueta());
        $this->assertSame('Hoy', $rango->presetEtiqueta());
    }

    public function test_la_etiqueta_termina_en_el_ultimo_dia_incluido(): void
    {
        // La trampa del rango: `fin` es exclusivo (00:00 del 1 de julio). Antes el PDF imprimía
        // ese instante, así que un reporte de junio decía que llegaba hasta julio.
        $rango = RangoFechas::desde(['desde' => '2026-06-01', 'hasta' => '2026-06-30'], 'America/Mexico_City');

        $this->assertSame('del 1 al 30 de junio de 2026', $rango->etiqueta());
        $this->assertSame('2026-06-30', $rango->meta()['fin_local']);
        $this->assertSame('Periodo personalizado', $rango->presetEtiqueta());
    }

    public function test_la_etiqueta_omite_lo_que_las_dos_fechas_comparten(): void
    {
        $mismoMes = RangoFechas::desde(['desde' => '2026-06-03', 'hasta' => '2026-06-09'], 'UTC');
        $this->assertSame('del 3 al 9 de junio de 2026', $mismoMes->etiqueta());

        $mismoAnio = RangoFechas::desde(['desde' => '2026-05-28', 'hasta' => '2026-06-09'], 'UTC');
        $this->assertSame('del 28 de mayo al 9 de junio de 2026', $mismoAnio->etiqueta());

        $cruceDeAnio = RangoFechas::desde(['desde' => '2025-12-28', 'hasta' => '2026-01-03'], 'UTC');
        $this->assertSame('del 28 de diciembre de 2025 al 3 de enero de 2026', $cruceDeAnio->etiqueta());
    }

    public function test_la_etiqueta_usa_la_zona_del_establecimiento(): void
    {
        // Son las 12:00 del 1 de julio en México, pero ya es el 2 en Tokio: cada local lee su día.
        $mexico = RangoFechas::desde(['preset' => 'hoy'], 'America/Mexico_City');
        $tokio = RangoFechas::desde(['preset' => 'hoy'], 'Asia/Tokyo');

        $this->assertSame('1 de julio de 2026', $mexico->etiqueta());
        $this->assertSame('2 de julio de 2026', $tokio->etiqueta());
    }

    public function test_meta_conserva_los_instantes_utc_ademas_de_lo_legible(): void
    {
        // Lo legible se AGREGA: `inicio`/`fin` siguen siendo el dato de máquina con fin exclusivo.
        $meta = RangoFechas::desde(['preset' => 'hoy'], 'America/Mexico_City')->meta();

        $this->assertSame('2026-07-01T06:00:00+00:00', $meta['inicio']);
        $this->assertSame('2026-07-02T06:00:00+00:00', $meta['fin']);
        $this->assertSame('2026-07-01', $meta['inicio_local']);
        $this->assertSame('2026-07-01', $meta['fin_local']);
    }
}
