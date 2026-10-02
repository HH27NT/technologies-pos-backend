<?php

namespace Tests\Support;

use App\Domain\Ordenes\Services\AgregarItemService;
use App\Domain\Ordenes\Services\CrearOrdenService;
use App\Models\ConfiguracionEstablecimiento;
use App\Models\Mesa;
use App\Models\Orden;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\TipoOrden;
use App\Models\Usuario;
use App\Support\Tenant\TenantContext;
use Spatie\Permission\PermissionRegistrar;

/**
 * Utilidades de prueba para el módulo de Órdenes (S7): fija el contexto de tenant
 * (para que los factories autollenen id_establecimiento), abre una caja, y siembra
 * mesas/productos. Se apoya en InteractuaConTenants para crear el tenant.
 */
trait ConstruyeOrdenes
{
    /** Fija el contexto de tenant + team de permisos para crear datos vía factory. */
    protected function enContextoDe(int $idEstablecimiento): void
    {
        app(TenantContext::class)->set($idEstablecimiento);
        app(PermissionRegistrar::class)->setPermissionsTeamId($idEstablecimiento);
    }

    /** Abre una caja para el tenant (satisface EnsureCajaAbierta y CrearOrdenService). */
    protected function abrirCaja(Usuario $usuario): SesionCaja
    {
        return SesionCaja::factory()->create([
            'id_usuario_apertura' => $usuario->id,
            'monto_inicial' => 500,
        ]);
    }

    protected function crearMesa(int $numero = 1, bool $activa = true): Mesa
    {
        return Mesa::create(['numero' => $numero, 'nombre' => "Mesa $numero", 'capacidad' => 4, 'activa' => $activa]);
    }

    protected function crearProducto(float $precioVenta = 100, bool $disponible = true): Producto
    {
        return Producto::factory()->create(['precio_venta' => $precioVenta, 'disponible' => $disponible]);
    }

    protected function tipoOrdenId(string $nombre = 'mesa'): int
    {
        return TipoOrden::where('nombre', $nombre)->value('id');
    }

    /** Activa el impuesto del establecimiento (config 1:1) con la tasa indicada. */
    protected function activarImpuesto(int $idEstablecimiento, float $tasa = 16): void
    {
        ConfiguracionEstablecimiento::where('id_establecimiento', $idEstablecimiento)
            ->update(['aplica_impuesto' => true, 'tasa_impuesto' => $tasa]);
    }

    /**
     * Orden `abierta` con un renglón, construida con los servicios reales (totales
     * congelados correctos). Requiere caja abierta + auth + contexto de tenant fijados.
     */
    protected function ordenAbiertaConProducto(Producto $producto, float $cantidad = 1, ?int $idMesa = null): Orden
    {
        $datos = ['id_tipo_orden' => $this->tipoOrdenId($idMesa !== null ? 'mesa' : 'barra')];
        if ($idMesa !== null) {
            $datos['id_mesa'] = $idMesa;
        }

        $orden = app(CrearOrdenService::class)->crear($datos);
        app(AgregarItemService::class)->agregar($orden, ['id_producto' => $producto->id, 'cantidad' => $cantidad]);

        return $orden->fresh();
    }

    /** Atajo: orden con un producto de precio fijo (sin control de inventario). */
    protected function ordenAbiertaConTotal(float $precio = 100, float $cantidad = 1): Orden
    {
        return $this->ordenAbiertaConProducto($this->crearProducto($precio), $cantidad);
    }
}
