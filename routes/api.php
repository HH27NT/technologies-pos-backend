<?php

use App\Http\Controllers\Api\V1\AuditoriaController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutorizacionController;
use App\Http\Controllers\Api\V1\CajaController;
use App\Http\Controllers\Api\V1\CategoriaController;
use App\Http\Controllers\Api\V1\ConfiguracionController;
use App\Http\Controllers\Api\V1\EstablecimientoController;
use App\Http\Controllers\Api\V1\ImpresoraController;
use App\Http\Controllers\Api\V1\InsumoController;
use App\Http\Controllers\Api\V1\MesaController;
use App\Http\Controllers\Api\V1\MeseroPinController;
use App\Http\Controllers\Api\V1\MiPinController;
use App\Http\Controllers\Api\V1\MovimientoController;
use App\Http\Controllers\Api\V1\OrdenController;
use App\Http\Controllers\Api\V1\PagoController;
use App\Http\Controllers\Api\V1\ProductoController;
use App\Http\Controllers\Api\V1\ProveedorController;
use App\Http\Controllers\Api\V1\RecetaController;
use App\Http\Controllers\Api\V1\ReporteController;
use App\Http\Controllers\Api\V1\RolController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\UnidadMedidaController;
use App\Http\Controllers\Api\V1\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
 * API v1 — Auth, Establecimientos, Configuración, Usuarios, Roles.
 * Cadena de middleware: auth:sanctum → resolve.tenant → tenant.activo (Arq §10).
 */
Route::prefix('v1')->group(function () {
    // Público (login + recuperación de contraseña; fuera de auth:sanctum, Convenciones §5.1)
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/recuperar', [AuthController::class, 'recuperar']);

    Route::middleware(['auth:sanctum', 'resolve.tenant', 'tenant.activo'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // M02 · Plataforma / Establecimientos (super_admin vía policy)
        Route::get('establecimientos', [EstablecimientoController::class, 'index']);
        Route::post('establecimientos', [EstablecimientoController::class, 'store']);
        Route::get('establecimientos/{id}', [EstablecimientoController::class, 'show']);
        Route::put('establecimientos/{id}', [EstablecimientoController::class, 'update']);
        Route::patch('establecimientos/{id}/activar', [EstablecimientoController::class, 'activar']);
        Route::post('establecimientos/{id}/asignar-admin', [EstablecimientoController::class, 'asignarAdmin']);
        Route::get('establecimientos/{id}/administradores', [EstablecimientoController::class, 'administradores']);
        Route::post('establecimientos/{id}/restablecer-acceso', [EstablecimientoController::class, 'restablecerAcceso']);

        // M03 · Configuración del establecimiento (admin del tenant)
        Route::get('configuracion', [ConfiguracionController::class, 'show']);
        Route::put('configuracion', [ConfiguracionController::class, 'update']);

        // M04 · Usuarios del establecimiento (admin)
        Route::get('usuarios', [UsuarioController::class, 'index']);
        Route::post('usuarios', [UsuarioController::class, 'store']);
        Route::get('usuarios/{id}', [UsuarioController::class, 'show']);
        Route::put('usuarios/{id}', [UsuarioController::class, 'update']);
        Route::patch('usuarios/{id}/activar', [UsuarioController::class, 'activar']);
        Route::post('usuarios/{id}/rol', [UsuarioController::class, 'rol']);

        // PIN de mesero (terminal compartida). Lo fija quien administra el personal, no su
        // dueño: en una barra con tablet compartida el mesero suele no tener credenciales
        // propias con las que entrar a fijárselo. El PIN identifica, nunca autoriza.
        Route::get('usuarios/{id}/mesero-pin', [MeseroPinController::class, 'show']);
        Route::put('usuarios/{id}/mesero-pin', [MeseroPinController::class, 'update']);
        Route::delete('usuarios/{id}/mesero-pin', [MeseroPinController::class, 'destroy']);

        // Roles. Lectura para quien gestiona personal; el CRUD del editor a medida
        // exige `roles.gestionar` (solo admin) vía RolPolicy. Los presets del catálogo
        // no son editables ni borrables: se clonan.
        Route::get('roles', [RolController::class, 'index']);
        Route::get('roles/permisos', [RolController::class, 'permisos']);
        Route::post('roles', [RolController::class, 'store']);
        Route::put('roles/{id}', [RolController::class, 'update']);
        Route::delete('roles/{id}', [RolController::class, 'destroy']);

        // M05 · Categorías de producto (admin)
        Route::get('categorias', [CategoriaController::class, 'index']);
        Route::post('categorias', [CategoriaController::class, 'store']);
        Route::get('categorias/{id}', [CategoriaController::class, 'show']);
        Route::put('categorias/{id}', [CategoriaController::class, 'update']);
        Route::patch('categorias/{id}/activar', [CategoriaController::class, 'activar']);

        // M06 · Productos (admin)
        Route::get('productos', [ProductoController::class, 'index']);
        Route::post('productos', [ProductoController::class, 'store']);
        Route::post('productos/lote', [ProductoController::class, 'lote']);
        Route::get('productos/{id}', [ProductoController::class, 'show']);
        Route::put('productos/{id}', [ProductoController::class, 'update']);
        Route::patch('productos/{id}/activar', [ProductoController::class, 'activar']);

        // M09 · Mesas (admin)
        Route::get('mesas', [MesaController::class, 'index']);
        Route::post('mesas', [MesaController::class, 'store']);
        Route::get('mesas/{id}', [MesaController::class, 'show']);
        Route::put('mesas/{id}', [MesaController::class, 'update']);
        Route::patch('mesas/{id}/activar', [MesaController::class, 'activar']);

        // M13 · Impresoras (admin)
        Route::get('impresoras', [ImpresoraController::class, 'index']);
        Route::post('impresoras', [ImpresoraController::class, 'store']);
        Route::get('impresoras/{id}', [ImpresoraController::class, 'show']);
        Route::put('impresoras/{id}', [ImpresoraController::class, 'update']);
        Route::patch('impresoras/{id}/activar', [ImpresoraController::class, 'activar']);

        // M08 · Proveedores (admin)
        Route::get('proveedores', [ProveedorController::class, 'index']);
        Route::post('proveedores', [ProveedorController::class, 'store']);
        Route::get('proveedores/{id}', [ProveedorController::class, 'show']);
        Route::put('proveedores/{id}', [ProveedorController::class, 'update']);
        Route::patch('proveedores/{id}/activar', [ProveedorController::class, 'activar']);

        // M08 · Unidades de medida (admin; globales solo lectura, P4)
        Route::get('unidades-medida', [UnidadMedidaController::class, 'index']);
        Route::post('unidades-medida', [UnidadMedidaController::class, 'store']);
        Route::get('unidades-medida/{id}', [UnidadMedidaController::class, 'show']);
        Route::put('unidades-medida/{id}', [UnidadMedidaController::class, 'update']);
        Route::delete('unidades-medida/{id}', [UnidadMedidaController::class, 'destroy']);

        // M08 · Insumos + kardex (admin)
        Route::get('insumos', [InsumoController::class, 'index']);
        Route::post('insumos', [InsumoController::class, 'store']);
        Route::get('insumos/{id}', [InsumoController::class, 'show']);
        Route::put('insumos/{id}', [InsumoController::class, 'update']);
        Route::patch('insumos/{id}/activar', [InsumoController::class, 'activar']);
        Route::get('insumos/{id}/kardex', [InsumoController::class, 'kardex']);

        // M08 · Movimientos de inventario (ledger; entrada/ajuste admin, merma ambos)
        Route::post('movimientos', [MovimientoController::class, 'store']);

        // M10 · Caja (admin y operador; compuerta de la venta)
        Route::post('caja/abrir', [CajaController::class, 'abrir']);
        Route::post('caja/cerrar', [CajaController::class, 'cerrar']);
        Route::get('caja/actual', [CajaController::class, 'actual']);
        Route::get('caja/historico', [CajaController::class, 'historico']);

        // M11 · Órdenes (admin y operador). Lectura libre; el ciclo abierto exige caja.
        Route::get('ordenes', [OrdenController::class, 'index']);
        Route::get('ordenes/{id}', [OrdenController::class, 'show']);

        // M12 · Saldo de la orden (lectura, sin compuerta de caja).
        Route::get('ordenes/{id}/saldo', [PagoController::class, 'saldo']);

        // Terminal compartida: el POS pregunta si debe pedir PIN, e identifica a quien teclea.
        // `identificar` NO es un login (no emite token ni cambia la sesión): solo resuelve a
        // quién atribuir la venta. Fuera de la compuerta de caja porque identificarse no es
        // una escritura del ciclo de venta.
        Route::get('terminal/modo', [MeseroPinController::class, 'modo']);
        Route::post('terminal/identificar', [MeseroPinController::class, 'identificar']);

        // Escritura del ciclo abierto: exige caja abierta (regla global 5).
        Route::middleware('caja.abierta')->group(function () {
            Route::post('ordenes', [OrdenController::class, 'store']);
            Route::post('ordenes/{id}/items', [OrdenController::class, 'agregarItem']);
            Route::put('ordenes/{id}/items/{itemId}', [OrdenController::class, 'modificarItem']);
            Route::post('ordenes/{id}/comanda', [OrdenController::class, 'comanda']);
            Route::post('ordenes/{id}/descuento', [OrdenController::class, 'descuento']);
            // M12 · Cobro de la orden (simple/dividido).
            Route::post('ordenes/{id}/pagos', [PagoController::class, 'store']);
        });

        // Cancelar ítem / anular orden: ADMIN directo (S7), sin compuerta de caja.
        Route::patch('ordenes/{id}/items/{itemId}/cancelar', [OrdenController::class, 'cancelarItem']);
        Route::patch('ordenes/{id}/anular', [OrdenController::class, 'anular']);
        // Reasignar mesero (traspaso): ADMIN/GERENTE, sin compuerta de caja.
        Route::patch('ordenes/{id}/reasignar', [OrdenController::class, 'reasignar']);

        // M13 · Impresión (comanda/ticket/PDF). Generar ticket de cobro, reimprimir
        // (auditada) y vista previa; ambos roles (P14). La impresión no bloquea el cobro.
        Route::post('ordenes/{id}/ticket', [TicketController::class, 'generar']);
        Route::post('tickets/{id}/reimprimir', [TicketController::class, 'reimprimir']);
        Route::get('tickets/{id}', [TicketController::class, 'show']);

        // M14.1 · PIN de autorización propio (self-service del autorizador). Es el secreto
        // que el operador teclea en el override de los 4 endpoints sensibles.
        Route::get('mi-pin', [MiPinController::class, 'show']);
        Route::put('mi-pin', [MiPinController::class, 'update']);
        Route::delete('mi-pin', [MiPinController::class, 'destroy']);

        // M14 · Autorizaciones (flujo de dos niveles). Operador solicita; admin resuelve.
        Route::get('autorizaciones', [AutorizacionController::class, 'index']);
        Route::post('autorizaciones', [AutorizacionController::class, 'store']);
        Route::patch('autorizaciones/{id}/aprobar', [AutorizacionController::class, 'aprobar']);
        Route::patch('autorizaciones/{id}/rechazar', [AutorizacionController::class, 'rechazar']);

        // M16 · Reportes y dashboard (solo lectura). Operativos: ambos roles con alcance
        // por rol (P21); gestión (inventario/cancelaciones/margen): solo admin. La
        // exportación PDF/Excel se encola (no bloquea la respuesta).
        Route::get('reportes/dashboard', [ReporteController::class, 'dashboard']);
        Route::get('reportes/ventas', [ReporteController::class, 'ventas']);
        Route::get('reportes/ventas-mensuales', [ReporteController::class, 'ventasMensuales']);
        Route::get('reportes/consumo-insumos', [ReporteController::class, 'consumoInsumos']);
        Route::get('reportes/top-recetas', [ReporteController::class, 'topRecetas']);
        Route::get('reportes/inventario', [ReporteController::class, 'inventario']);
        Route::get('reportes/caja', [ReporteController::class, 'caja']);
        Route::get('reportes/medios-pago', [ReporteController::class, 'mediosPago']);
        Route::get('reportes/ventas-por-mesero', [ReporteController::class, 'ventasPorMesero']);
        Route::get('reportes/cancelaciones', [ReporteController::class, 'cancelaciones']);
        Route::get('reportes/margen', [ReporteController::class, 'margen']);
        Route::post('reportes/exportar', [ReporteController::class, 'exportar']);
        Route::get('reportes/exportaciones/{archivo}', [ReporteController::class, 'descargar']);

        // M15/M16 · Auditoría: del tenant (admin) y global (super_admin).
        Route::get('auditoria', [AuditoriaController::class, 'index']);
        Route::get('auditoria/global', [AuditoriaController::class, 'global']);

        // M07 · Recetas (BOM, admin)
        Route::get('recetas', [RecetaController::class, 'index']);
        Route::post('recetas', [RecetaController::class, 'store']);
        // Antes de `recetas/{id}` por claridad: la receta completa de un producto se
        // escribe de una vez, no renglón por renglón.
        Route::put('recetas/producto/{idProducto}', [RecetaController::class, 'reemplazarDeProducto']);
        Route::get('recetas/{id}', [RecetaController::class, 'show']);
        Route::put('recetas/{id}', [RecetaController::class, 'update']);
        Route::delete('recetas/{id}', [RecetaController::class, 'destroy']);
    });
});
