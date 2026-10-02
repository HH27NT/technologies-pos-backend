<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Ordenes\MeseroEfectivo;
use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\RangoFechas;
use App\Models\Pago;
use App\Models\Usuario;

/**
 * M16 · Qué vendió y qué cobró cada mesero en el rango.
 *
 * Es el reporte que justifica todo el bloque del PIN: sin él, la firma se guarda y nadie la ve.
 *
 * **Son dos preguntas distintas y el reporte responde las dos por separado**, porque una orden
 * la puede abrir una persona y cobrarla otra (pasa a diario: el compañero cierra la mesa
 * mientras el mesero sigue en el piso):
 *
 * - **Atendió** → agrupa por el mesero efectivo de la ORDEN. Es el desempeño: de quién era la
 *   mesa. Es la columna que sirve para propinas o comisiones.
 * - **Cobró** → agrupa por el mesero efectivo del PAGO. Es la responsabilidad sobre el dinero:
 *   por manos de quién entró. Es la columna que sirve para el arqueo.
 *
 * Nunca se suman entre sí: son **dos repartos del mismo dinero**, así que cada columna totaliza
 * lo mismo que el reporte de ventas del rango. Sumarlas duplicaría el total.
 *
 * Ambas se calculan sobre **pagos cobrados**, no sobre `ordenes.total`. Si "atendió" saliera de
 * las órdenes, arrastraría las abiertas y las nunca cobradas, y el reporte dejaría de cuadrar
 * contra el de ventas sin dar señal de error.
 *
 * En el modo de dispositivo por mesero (`id_mesero` nulo en ambas tablas) las dos columnas son
 * idénticas salvo que alguien cobre la orden de otro desde su propia cuenta: la misma consulta
 * acierta en los dos modos sin saber cuál está activo.
 */
class VentasPorMeseroQuery implements ReporteQuery
{
    public function ejecutar(RangoFechas $rango, AlcanceReporte $alcance): array
    {
        // El JOIN trae la firma de la orden junto a la del pago. El scope de tenant filtra por
        // `pagos.id_establecimiento` (va calificado), y la orden entra por su FK, así que no
        // hay fuga entre locales.
        $q = Pago::query()
            ->join('ordenes', 'ordenes.id', '=', 'pagos.id_orden')
            ->where('pagos.pagado_at', '>=', $rango->inicioUtc)
            ->where('pagos.pagado_at', '<', $rango->finUtc);

        if ($alcance->limitado) {
            $q->whereIn('ordenes.id_sesion_caja', $alcance->sesionesPermitidas ?: [0]);
        }

        $pagos = $q->get([
            'pagos.id_orden',
            'pagos.id_usuario as cobro_usuario',
            'pagos.id_mesero as cobro_mesero',
            'pagos.monto',
            'ordenes.id_usuario as orden_usuario',
            'ordenes.id_mesero as orden_mesero',
        ]);

        /** @var array<int, array{ordenes: array<int, true>, monto_atendido: float, cobros: int, monto_cobrado: float}> */
        $personas = [];
        $vacio = ['ordenes' => [], 'monto_atendido' => 0.0, 'cobros' => 0, 'monto_cobrado' => 0.0];

        foreach ($pagos as $pago) {
            $monto = (float) $pago->monto;

            $atendio = MeseroEfectivo::entre($pago->orden_mesero, $pago->orden_usuario) ?? 0;
            $personas[$atendio] ??= $vacio;
            // Set de órdenes, no un contador: un pago dividido no son dos mesas atendidas.
            $personas[$atendio]['ordenes'][(int) $pago->id_orden] = true;
            $personas[$atendio]['monto_atendido'] += $monto;

            $cobro = MeseroEfectivo::entre($pago->cobro_mesero, $pago->cobro_usuario) ?? 0;
            $personas[$cobro] ??= $vacio;
            $personas[$cobro]['cobros']++;
            $personas[$cobro]['monto_cobrado'] += $monto;
        }

        // Un solo SELECT para los nombres, en lugar de un eager-load de cuatro relaciones que en
        // la mayoría de las filas apuntan al mismo puñado de personas.
        $nombres = Usuario::whereIn('id', array_keys($personas))->pluck('nombre', 'id');

        $filas = [];
        foreach ($personas as $id => $p) {
            // El orden de las claves ES el orden de las columnas: el exportador vuelca las filas
            // con `array_values` (ReporteExcel). Cambiar uno sin el otro descuadra el Excel.
            $filas[] = [
                'mesero' => $nombres[$id] ?? '—',
                'ordenes' => count($p['ordenes']),
                'monto_atendido' => round($p['monto_atendido'], 2),
                'cobros' => $p['cobros'],
                'monto_cobrado' => round($p['monto_cobrado'], 2),
            ];
        }

        // Encabeza quien más vendió; el cobro desempata (quien solo cobra queda al final).
        usort($filas, fn ($a, $b) => [$b['monto_atendido'], $b['monto_cobrado']] <=> [$a['monto_atendido'], $a['monto_cobrado']]);

        $total = round($pagos->sum(fn (Pago $p) => (float) $p->monto), 2);

        return [
            'reporte' => 'ventas-por-mesero',
            'titulo' => 'Ventas por mesero',
            'rango' => $rango->meta(),
            'columnas' => ['Mesero', 'Órdenes atendidas', 'Monto atendido', 'Cobros', 'Monto cobrado'],
            'filas' => $filas,
            'resumen' => [
                'meseros' => count($filas),
                'operaciones' => $pagos->count(),
                // Un solo total: las dos columnas suman esto mismo, repartido distinto.
                'monto' => $total,
            ],
        ];
    }
}
