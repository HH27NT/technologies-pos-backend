<?php

namespace App\Console\Commands;

use App\Domain\Inventario\Services\ReconciliarStockService;
use App\Models\Insumo;
use Illuminate\Console\Command;

/**
 * M08 · Cablea a producción la salvaguarda de integridad del ledger (R4).
 * Recorre los insumos (de todos los tenants: en consola no hay TenantContext, §7),
 * compara insumos.stock_actual (cache) con la suma firmada del ledger y corrige las
 * divergencias. Pensado para ejecución manual o tarea programada.
 */
class ReconciliarInventarioCommand extends Command
{
    protected $signature = 'inventario:reconciliar
        {--insumo= : Reconciliar solo el insumo con este id}
        {--dry-run : Solo reportar divergencias, sin persistir cambios}';

    protected $description = 'Recalcula el stock de los insumos desde el ledger y corrige divergencias cache↔ledger.';

    /** Tolerancia: stock_actual es DECIMAL(12,3); por debajo de medio milésimo no es divergencia. */
    private const EPSILON = 0.0005;

    public function handle(ReconciliarStockService $service): int
    {
        $query = Insumo::query()->withTrashed()->orderBy('id');

        if ($id = $this->option('insumo')) {
            $query->whereKey($id);
        }

        $dryRun = (bool) $this->option('dry-run');
        $revisados = 0;
        $divergencias = 0;

        $query->each(function (Insumo $insumo) use ($service, $dryRun, &$revisados, &$divergencias) {
            $revisados++;

            $cache = (float) $insumo->stock_actual;
            $ledger = $service->calcularDesdeLedger($insumo->id);

            if (abs($cache - $ledger) < self::EPSILON) {
                return;
            }

            $divergencias++;
            $this->warn(sprintf(
                'Insumo #%d "%s": cache=%.3f ledger=%.3f%s',
                $insumo->id,
                $insumo->nombre,
                $cache,
                $ledger,
                $dryRun ? ' (dry-run, sin corregir)' : ' → corregido',
            ));

            if (! $dryRun) {
                $service->reconciliar($insumo);
            }
        });

        $this->info(sprintf(
            '%d insumo(s) revisado(s), %d divergencia(s)%s.',
            $revisados,
            $divergencias,
            $dryRun ? ' (dry-run, sin cambios)' : ' corregida(s)',
        ));

        return self::SUCCESS;
    }
}
