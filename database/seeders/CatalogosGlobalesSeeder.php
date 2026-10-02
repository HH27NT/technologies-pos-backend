<?php

namespace Database\Seeders;

use App\Models\TipoOrden;
use App\Models\TipoPago;
use App\Models\UnidadMedida;
use Illuminate\Database\Seeder;

/**
 * Catálogos globales (sin tenant), idempotente.
 * tipos_orden, tipos_pago y unidades_medida predefinidas (id_establecimiento NULL, P4).
 */
class CatalogosGlobalesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['mesa', 'barra', 'llevar'] as $nombre) {
            TipoOrden::firstOrCreate(['nombre' => $nombre], ['activo' => true]);
        }

        foreach (['efectivo', 'tarjeta', 'transferencia'] as $nombre) {
            TipoPago::firstOrCreate(['nombre' => $nombre], ['activo' => true]);
        }

        $unidades = [
            ['nombre' => 'Mililitro', 'abreviacion' => 'ml'],
            ['nombre' => 'Litro', 'abreviacion' => 'l'],
            ['nombre' => 'Gramo', 'abreviacion' => 'g'],
            ['nombre' => 'Kilogramo', 'abreviacion' => 'kg'],
            ['nombre' => 'Pieza', 'abreviacion' => 'pza'],
            ['nombre' => 'Onza', 'abreviacion' => 'oz'],
            ['nombre' => 'Botella', 'abreviacion' => 'bot'],
            ['nombre' => 'Lata', 'abreviacion' => 'lata'],
            ['nombre' => 'Caja', 'abreviacion' => 'caja'],
            ['nombre' => 'Porción', 'abreviacion' => 'porc'],
        ];

        foreach ($unidades as $u) {
            // id_establecimiento NULL => unidad predefinida global, de solo lectura para el tenant.
            UnidadMedida::firstOrCreate(
                ['nombre' => $u['nombre'], 'id_establecimiento' => null],
                ['abreviacion' => $u['abreviacion']]
            );
        }
    }
}
