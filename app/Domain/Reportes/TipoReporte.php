<?php

namespace App\Domain\Reportes;

use App\Domain\Reportes\Queries\CajaQuery;
use App\Domain\Reportes\Queries\CancelacionesQuery;
use App\Domain\Reportes\Queries\ConsumoInsumosQuery;
use App\Domain\Reportes\Queries\DashboardQuery;
use App\Domain\Reportes\Queries\InventarioQuery;
use App\Domain\Reportes\Queries\MargenQuery;
use App\Domain\Reportes\Queries\MediosPagoQuery;
use App\Domain\Reportes\Queries\TopRecetasQuery;
use App\Domain\Reportes\Queries\VentasMensualesQuery;
use App\Domain\Reportes\Queries\VentasPorMeseroQuery;
use App\Domain\Reportes\Queries\VentasQuery;

/**
 * M16 · Catálogo de reportes. Fuente única del literal del reporte: la ruta, la
 * validación de exportación y el job derivan de aquí. `soloAdmin` marca los reportes
 * de gestión que el OPERADOR no ve (P21: su alcance es su turno, no el establecimiento).
 */
enum TipoReporte: string
{
    case Dashboard = 'dashboard';
    case Ventas = 'ventas';
    case VentasPorMesero = 'ventas-por-mesero';
    case VentasMensuales = 'ventas-mensuales';
    case Inventario = 'inventario';
    case Caja = 'caja';
    case MediosPago = 'medios-pago';
    case Cancelaciones = 'cancelaciones';
    case Margen = 'margen';
    case ConsumoInsumos = 'consumo-insumos';
    case TopRecetas = 'top-recetas';

    /** Reportes de gestión del establecimiento: solo ADMIN (P21). */
    public function soloAdmin(): bool
    {
        return in_array(
            $this,
            [self::Inventario, self::Cancelaciones, self::Margen, self::ConsumoInsumos, self::TopRecetas, self::VentasPorMesero],
            true,
        );
    }

    public function titulo(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard del día',
            self::Ventas => 'Reporte de ventas',
            self::VentasPorMesero => 'Ventas por mesero',
            self::VentasMensuales => 'Ventas por mes',
            self::Inventario => 'Reporte de inventario',
            self::Caja => 'Reporte de caja',
            self::MediosPago => 'Reporte de medios de pago',
            self::Cancelaciones => 'Reporte de cancelaciones',
            self::Margen => 'Reporte de utilidad / margen',
            self::ConsumoInsumos => 'Insumos más utilizados',
            self::TopRecetas => 'Insumos en más recetas',
        };
    }

    /** @return class-string<Queries\ReporteQuery> */
    public function queryClass(): string
    {
        return match ($this) {
            self::Dashboard => DashboardQuery::class,
            self::Ventas => VentasQuery::class,
            self::VentasPorMesero => VentasPorMeseroQuery::class,
            self::VentasMensuales => VentasMensualesQuery::class,
            self::Inventario => InventarioQuery::class,
            self::Caja => CajaQuery::class,
            self::MediosPago => MediosPagoQuery::class,
            self::Cancelaciones => CancelacionesQuery::class,
            self::Margen => MargenQuery::class,
            self::ConsumoInsumos => ConsumoInsumosQuery::class,
            self::TopRecetas => TopRecetasQuery::class,
        };
    }

    /** @return list<string> valores válidos para Rule::in de la exportación. */
    public static function valores(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
