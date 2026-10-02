<?php

namespace App\Domain\Catalogo\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Inventario\Services\GestionarRecetaService;
use App\Domain\Inventario\Services\GuardarInsumoService;
use App\Models\Insumo;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * M06 · Escritura del catálogo de productos (alta, edición y disponibilidad).
 * Atómica y auditada (§15). Un producto con controla_inventario = true puede
 * crearse sin receta; su descuento de inventario al cobrar dependerá de que la
 * receta exista (contrato del Sprint 5), pero el catálogo no lo bloquea.
 */
class GuardarProductoService
{
    public function __construct(
        private readonly RegistrarAuditoriaService $auditoria,
        private readonly GuardarInsumoService $insumos,
        private readonly GestionarRecetaService $recetas,
    ) {}

    private const CAMPOS = [
        'id_categoria', 'nombre', 'descripcion', 'precio_venta',
        'costo_referencia', 'controla_inventario', 'disponible', 'sku',
    ];

    public function crear(array $datos): Producto
    {
        return DB::transaction(fn () => $this->crearUno($datos));
    }

    /**
     * Alta de un producto, con el atajo "sale del almacén" si el payload trae `insumo`.
     * Vive aparte de `crear()` para que el lote reutilice exactamente el mismo camino:
     * un producto dado de alta suelto y otro dado de alta en la rejilla deben quedar
     * idénticos, incluidos el asiento de auditoría y la receta.
     */
    private function crearUno(array $datos): Producto
    {
        $insumo = $datos['insumo'] ?? null;
        unset($datos['insumo']);

        if ($insumo !== null) {
            // Crear la receta y dejar el interruptor apagado sería construir algo que
            // nunca se usa: al cobrar, `DescontarInventarioService` solo mira los
            // productos con controla_inventario.
            $datos['controla_inventario'] = true;
            // El costo del producto lo ignora `MargenQuery` cuando hay inventario: el
            // que cuenta es el del insumo, y ahí es donde se guarda.
            unset($datos['costo_referencia']);
        }

        $producto = Producto::create($datos);

        $this->auditoria->registrar(
            accion: 'producto.creado',
            entidad: 'productos',
            entidadId: $producto->id,
            datosDespues: $producto->only(self::CAMPOS),
        );

        if ($insumo !== null) {
            $this->vincularInsumoPropio($producto, $insumo);
        }

        return $producto;
    }

    /**
     * Atajo "esto sale del almacén tal cual": el insumo se llama como el producto y la
     * receta es 1:1. Ahorra dos pantallas por cada botella o lata que se vende sin
     * preparar, que en una cantina es casi todo el menú.
     *
     * Si ya existe un insumo con ese nombre **se reutiliza**: duplicarlo repartiría el
     * stock de la misma botella entre dos registros y el inventario mentiría en
     * silencio. Además es lo correcto cuando dos productos comparten insumo (la botella
     * suelta y el balde de seis).
     */
    private function vincularInsumoPropio(Producto $producto, array $datos): void
    {
        // La comparación va en minúsculas por código: LIKE/= distinguen mayúsculas en
        // PostgreSQL (producción) y no en SQLite (pruebas).
        $existente = Insumo::query()
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($producto->nombre))])
            ->first();

        $insumo = $existente ?? $this->insumos->crear([
            'nombre' => $producto->nombre,
            'id_unidad_medida' => $datos['id_unidad_medida'],
            'costo_unitario' => $datos['costo_unitario'] ?? null,
        ]);

        $this->recetas->crear([
            'id_producto' => $producto->id,
            'id_insumo' => $insumo->id,
            'cantidad' => 1,
        ]);
    }

    /**
     * Alta por lote para la carga inicial del menú. **Todo o nada**: una fila mala
     * no deja el catálogo a medias. La alternativa (guardar lo válido y saltar lo
     * malo) obliga a adivinar qué entró y, como productos no exige nombre único,
     * reintentar el mismo pegado duplicaría lo ya creado.
     *
     * Cada producto se audita por separado, igual que en un alta suelta: el historial
     * de un producto no debería depender de cómo se dio de alta.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return Collection<int, Producto>
     */
    public function crearLote(array $filas): Collection
    {
        return DB::transaction(function () use ($filas) {
            $creados = new Collection;

            foreach ($filas as $datos) {
                $creados->push($this->crearUno($datos));
            }

            return $creados;
        });
    }

    public function actualizar(Producto $producto, array $datos): Producto
    {
        return DB::transaction(function () use ($producto, $datos) {
            $antes = $producto->only(array_keys($datos));
            $producto->fill($datos)->save();

            $this->auditoria->registrar(
                accion: 'producto.actualizado',
                entidad: 'productos',
                entidadId: $producto->id,
                datosAntes: $antes,
                datosDespues: $producto->only(array_keys($datos)),
            );

            return $producto;
        });
    }

    public function cambiarEstado(Producto $producto, bool $disponible): Producto
    {
        return DB::transaction(function () use ($producto, $disponible) {
            $antes = $producto->disponible;
            $producto->disponible = $disponible;
            $producto->save();

            $this->auditoria->registrar(
                accion: $disponible ? 'producto.disponible' : 'producto.no_disponible',
                entidad: 'productos',
                entidadId: $producto->id,
                datosAntes: ['disponible' => $antes],
                datosDespues: ['disponible' => $disponible],
            );

            return $producto;
        });
    }
}
