<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Ordenes\EstadoOrden;
use App\Domain\Reportes\AlcanceReporte;
use App\Domain\Reportes\RangoFechas;
use App\Models\DetalleOrden;
use App\Models\Orden;
use App\Models\Producto;

/**
 * M16 · Utilidad / margen (solo ADMIN). Por producto vendido en el rango: ingreso menos
 * costo. La FUENTE DEL COSTO se resuelve por producto (P20): `controla_inventario = true`
 * → suma del costo de los insumos de su receta; `= false` → `costo_referencia`. Usa el
 * costo VIGENTE del insumo (el costeo histórico por lote queda fuera del MVP).
 *
 * UN COSTO QUE NO SE CAPTURÓ NO VALE CERO. `costo_referencia` y `costo_unitario` son
 * columnas nullable, así que castear a float volvía indistinguibles "no lo sé" y "sale
 * gratis": el producto aparecía con 100% de utilidad y el KPI del dashboard lo repetía
 * con toda confianza. Ahora el costo del producto es `null` cuando no se puede calcular
 * —y con él su margen y su porcentaje—, que la UI pinta como "—".
 *
 * Qué hace nulo a un producto: no tener `costo_referencia` (si no controla inventario);
 * no tener receta o que ALGÚN insumo de ella no tenga costo (si sí controla). El costo
 * parcial de una receta a medio capturar sería otra media verdad, así que no se reporta.
 *
 * El RESUMEN suma lo que sí se sabe y añade `productos_sin_costo` para que ese total se
 * lea por lo que es; si NO se conoce ningún costo, no hay cifra que dar y `costo` y
 * `margen` también salen nulos —si no, el margen del periodo sería el ingreso entero—.
 * `margen_pct` se anula en cuanto falta un solo costo: un porcentaje implica que el
 * denominador está completo, y era justo el número que mentía.
 */
class MargenQuery implements ReporteQuery
{
    public function ejecutar(RangoFechas $rango, AlcanceReporte $alcance): array
    {
        $ordenesQ = Orden::query()
            ->where('estado', EstadoOrden::Pagada->value)
            ->where('cerrada_at', '>=', $rango->inicioUtc)
            ->where('cerrada_at', '<', $rango->finUtc);
        $alcance->aplicarAOrdenes($ordenesQ);
        $idsOrdenes = $ordenesQ->pluck('id');

        $items = DetalleOrden::query()
            ->whereIn('id_orden', $idsOrdenes)
            ->where('estado_item', 'activo')
            ->with('producto.insumos')
            ->get();

        $filas = $items
            ->groupBy('id_producto')
            ->map(function ($g) {
                $producto = $g->first()->producto;
                $cantidad = round($g->sum(fn ($d) => (float) $d->cantidad), 3);
                $ingreso = round($g->sum(fn ($d) => (float) $d->subtotal), 2);
                $costoUnitario = $this->costoUnitario($producto);
                $costo = $costoUnitario === null ? null : round($costoUnitario * $cantidad, 2);
                $margen = $costo === null ? null : round($ingreso - $costo, 2);

                return [
                    'producto' => $producto?->nombre ?? '—',
                    'cantidad' => $cantidad,
                    'ingreso' => $ingreso,
                    'costo' => $costo,
                    'margen' => $margen,
                    'margen_pct' => $margen !== null && $ingreso > 0
                        ? round($margen / $ingreso * 100, 2)
                        : null,
                ];
            })
            // Las filas de margen desconocido van al final: -INF, no PHP_FLOAT_MIN,
            // que es el float POSITIVO más pequeño y las habría dejado entre las de
            // margen negativo.
            ->sortByDesc(fn ($fila) => $fila['margen'] ?? -INF)
            ->values()
            ->all();

        $sinCosto = count(array_filter($filas, fn ($fila) => $fila['costo'] === null));
        $conCosto = count($filas) - $sinCosto;
        $ingresoTotal = round(array_sum(array_column($filas, 'ingreso')), 2);

        // Con algún costo conocido, el total es la suma de lo que sí se sabe y
        // `productos_sin_costo` avisa de que va corto. Si NO se sabe ninguno, la suma
        // de nada no es un total parcial: no hay cifra, y decir "$0.00 de costo" o
        // "el margen es todo el ingreso" sería el cero con confianza otra vez.
        $costoTotal = $conCosto === 0 ? null : round(array_sum(array_column($filas, 'costo')), 2);
        $margenTotal = $costoTotal === null ? null : round($ingresoTotal - $costoTotal, 2);

        return [
            'reporte' => 'margen',
            'titulo' => 'Reporte de utilidad / margen',
            'rango' => $rango->meta(),
            'columnas' => ['Producto', 'Cantidad', 'Ingreso', 'Costo', 'Margen', 'Margen %'],
            'filas' => $filas,
            'resumen' => [
                'ingreso' => $ingresoTotal,
                'costo' => $costoTotal,
                'margen' => $margenTotal,
                'margen_pct' => $sinCosto === 0 && $ingresoTotal > 0
                    ? round((float) $margenTotal / $ingresoTotal * 100, 2)
                    : null,
                'productos_sin_costo' => $sinCosto,
            ],
        ];
    }

    /**
     * Costo de producir una unidad del producto según la fuente configurable (P20), o
     * `null` si no se puede saber porque el dato no está capturado.
     */
    private function costoUnitario(?Producto $producto): ?float
    {
        if ($producto === null) {
            return null;
        }

        if (! $producto->controla_inventario) {
            return $producto->costo_referencia === null
                ? null
                : (float) $producto->costo_referencia;
        }

        // Sin receta no hay nada que sumar: el producto descuenta inventario pero nadie
        // dijo de qué se compone, así que su costo es desconocido, no cero.
        if ($producto->insumos->isEmpty()) {
            return null;
        }

        if ($producto->insumos->contains(fn ($insumo) => $insumo->costo_unitario === null)) {
            return null;
        }

        return (float) $producto->insumos->sum(
            fn ($insumo) => (float) $insumo->pivot->cantidad * (float) $insumo->costo_unitario,
        );
    }
}
