<?php

namespace App\Domain\Usuarios;

/**
 * ÚNICA fuente de verdad del modelo de roles: qué permisos existen, qué roles
 * existen y qué lleva cada uno. Contrato en docs/MatrizRoles.md.
 *
 * Vive en el dominio (no en el seeder) a propósito: el seeder, el provisionamiento
 * de tenants, la validación de asignación y el comando de sincronización son todos
 * CONSUMIDORES de esta lista. Antes cada uno tenía su propia copia de "los roles que
 * existen" y bastó con que una se quedara atrás —CrearEstablecimientoService seguía
 * provisionando solo admin/operador tras introducir gerente/mesero— para que los
 * tenants nuevos nacieran sin la mitad de sus roles.
 *
 * Para AGREGAR UN ROL: se define su paquete de permisos aquí y se suma a TENANT.
 * Nada más. Los tenants nuevos lo reciben al crearse y los ya existentes en el
 * siguiente arranque (el entrypoint corre `roles:sincronizar`, que es idempotente).
 */
class CatalogoRoles
{
    /** Catálogo completo de permisos (§8 de Arquitectura). */
    public const PERMISOS = [
        'establecimientos.gestionar', 'establecimientos.activar', 'establecimientos.asignar_admin',
        'establecimientos.restablecer_acceso',
        'metricas.globales', 'auditoria.global',
        'configuracion.editar', 'usuarios.gestionar',
        // Gestionar usuarios que son (o pasan a ser) ADMIN. Solo el admin lo tiene: el
        // gerente gestiona personal pero NO puede crear/asignar/tocar admins (anti-escalada).
        'usuarios.gestionar_admins',
        // Editor de roles a medida. Solo el admin: quien puede definir permisos puede
        // fabricarse un rol equivalente a admin, así que es tan sensible como
        // `usuarios.gestionar_admins` y va siempre de la mano con él.
        'roles.gestionar',
        'categorias.gestionar', 'productos.gestionar', 'recetas.gestionar',
        // Leer el catálogo NO es gestionarlo: el operador/mesero necesitan ver productos,
        // categorías y mesas para vender (rejilla y selección de mesa del POS), pero no
        // pueden crearlos ni editarlos.
        'categorias.ver', 'productos.ver', 'mesas.ver',
        'insumos.gestionar', 'proveedores.gestionar', 'mesas.gestionar', 'impresoras.gestionar',
        'unidades.gestionar',
        'caja.ver', 'caja.abrir', 'caja.cerrar',
        'ordenes.crear', 'ordenes.agregar_item', 'ordenes.cobrar', 'ordenes.aplicar_descuento',
        'tickets.imprimir', 'tickets.reimprimir',
        'inventario.merma', 'inventario.entrada', 'inventario.ajustar',
        'ordenes.cancelar_item', 'ordenes.anular', 'ordenes.reasignar',
        'autorizaciones.solicitar', 'autorizaciones.aprobar',
        'reportes.ver', 'reportes.ver_limitado', 'reportes.ver_dashboard',
        'auditoria.ver',
    ];

    /** SUPER_ADMIN: opera la plataforma, no el negocio. Rol global, sin team. */
    public const PERMISOS_SUPER_ADMIN = [
        'establecimientos.gestionar', 'establecimientos.activar', 'establecimientos.asignar_admin',
        'establecimientos.restablecer_acceso',
        'metricas.globales', 'auditoria.global',
    ];

    /**
     * ADMIN: dueño del negocio, autoridad máxima dentro del tenant.
     *
     * INVARIANTE: contiene TODOS los permisos otorgables a un tenant (es decir, el
     * catálogo menos los de plataforma). No es un detalle estético — el editor de roles
     * aplica "no puedes otorgar un permiso que tú no tienes", así que un hueco aquí le
     * impide al dueño crear un rol perfectamente legítimo. Se rompió al clonar el preset
     * `mesero`, que llevaba dos permisos que el admin no tenía. Blindado con
     * `EditorRolesTest::test_el_admin_tiene_todos_los_permisos_otorgables`.
     */
    public const PERMISOS_ADMIN = [
        'configuracion.editar', 'usuarios.gestionar', 'usuarios.gestionar_admins', 'roles.gestionar',
        'categorias.gestionar', 'productos.gestionar', 'recetas.gestionar',
        'categorias.ver', 'productos.ver', 'mesas.ver',
        'insumos.gestionar', 'proveedores.gestionar', 'mesas.gestionar', 'impresoras.gestionar',
        'unidades.gestionar',
        'caja.ver', 'caja.abrir', 'caja.cerrar',
        'ordenes.crear', 'ordenes.agregar_item', 'ordenes.cobrar', 'ordenes.aplicar_descuento',
        'tickets.imprimir', 'tickets.reimprimir',
        'inventario.merma', 'inventario.entrada', 'inventario.ajustar',
        'ordenes.cancelar_item', 'ordenes.anular', 'ordenes.reasignar',
        // `solicitar` además de `aprobar`: el admin ejecuta directo (los controllers usan
        // el permiso directo y solo caen a "solicitar" como fallback), pero necesita
        // TENER el permiso para poder otorgarlo al crear roles a medida.
        'autorizaciones.solicitar', 'autorizaciones.aprobar',
        // `ver_limitado` es un subconjunto de `ver`, y todo el código evalúa `ver`
        // primero (AlcanceReporte, ReportePolicy, DashboardInicio), así que no cambia
        // lo que el admin ve; lo habilita para clonar roles operativos.
        // `ver_dashboard` es el único de los tres que el GERENTE no tiene (ver abajo):
        // el dashboard de decisiones es la landing exclusiva del dueño.
        'reportes.ver', 'reportes.ver_limitado', 'reportes.ver_dashboard', 'auditoria.ver',
    ];

    /**
     * GERENTE: casi-admin operativo. Igual que ADMIN salvo las decisiones de dueño —
     * NO tiene configuración del sistema (`configuracion.editar`), auditoría
     * (`auditoria.ver`), gestión de admins (`usuarios.gestionar_admins`) ni el
     * dashboard de decisiones (`reportes.ver_dashboard`): sigue viendo el resto de
     * los reportes (`reportes.ver`), solo que su landing es el POS, no el dashboard.
     * Ver EspecificacionFuncional.md.
     */
    public const PERMISOS_GERENTE = [
        'usuarios.gestionar',
        'categorias.gestionar', 'productos.gestionar', 'recetas.gestionar',
        'categorias.ver', 'productos.ver', 'mesas.ver',
        'insumos.gestionar', 'proveedores.gestionar', 'mesas.gestionar', 'impresoras.gestionar',
        'unidades.gestionar',
        'caja.ver', 'caja.abrir', 'caja.cerrar',
        'ordenes.crear', 'ordenes.agregar_item', 'ordenes.cobrar', 'ordenes.aplicar_descuento',
        'tickets.imprimir', 'tickets.reimprimir',
        'inventario.merma', 'inventario.entrada', 'inventario.ajustar',
        'ordenes.cancelar_item', 'ordenes.anular', 'ordenes.reasignar',
        'autorizaciones.aprobar',
        'reportes.ver',
    ];

    /** OPERADOR: cajero. Abre/cierra caja y cobra; lo sensible lo SOLICITA (🔐). */
    public const PERMISOS_OPERADOR = [
        // Lectura del catálogo: sin esto la rejilla de productos del POS queda vacía
        // (el operador podría agregar ítems pero no verlos para elegirlos).
        'categorias.ver', 'productos.ver',
        'mesas.ver',
        'caja.ver', 'caja.abrir', 'caja.cerrar',
        'ordenes.crear', 'ordenes.agregar_item', 'ordenes.cobrar', 'ordenes.aplicar_descuento',
        'tickets.imprimir', 'tickets.reimprimir',
        'inventario.merma',
        // El operador NO tiene los permisos directos 🔐 (cancelar_item, anular, entrada,
        // ajustar): solo puede SOLICITAR su autorización (flujo de dos niveles, S9).
        'autorizaciones.solicitar',
        'reportes.ver_limitado',
    ];

    /**
     * MESERO: toma la orden y cierra su cuenta (cobra + imprime). Ve el catálogo y las
     * mesas para vender. Las operaciones sensibles las SOLICITA (🔐), no las ejecuta.
     * Sin caja, sin descuento, sin gestión de inventario por defecto.
     */
    public const PERMISOS_MESERO = [
        'categorias.ver', 'productos.ver', 'mesas.ver',
        // Lectura del estado de caja (compuerta del POS): el mesero NO abre/cierra caja,
        // pero necesita saber si el turno tiene caja abierta para poder crear y cobrar.
        'caja.ver',
        'ordenes.crear', 'ordenes.agregar_item', 'ordenes.cobrar',
        'tickets.imprimir',
        'autorizaciones.solicitar',
        'reportes.ver_limitado',
    ];

    /**
     * Roles que se materializan POR ESTABLECIMIENTO (teams de Spatie), en orden de
     * autoridad descendente. Todo tenant nace con los cuatro.
     *
     * @var array<string, string[]> nombre del rol => sus permisos
     */
    public const TENANT = [
        'admin' => self::PERMISOS_ADMIN,
        'gerente' => self::PERMISOS_GERENTE,
        'operador' => self::PERMISOS_OPERADOR,
        'mesero' => self::PERMISOS_MESERO,
    ];

    /**
     * Roles GLOBALES de plataforma: no pertenecen a ningún establecimiento (team nulo)
     * y nunca son asignables desde el módulo de usuarios de un tenant.
     *
     * @var array<string, string[]>
     */
    public const PLATAFORMA = [
        'super_admin' => self::PERMISOS_SUPER_ADMIN,
    ];

    /** Nombre del rol con autoridad máxima dentro del tenant (el que se protege). */
    public const ROL_ADMIN = 'admin';

    /** @return string[] nombres de los roles de tenant, en orden de autoridad */
    public static function nombresTenant(): array
    {
        return array_keys(self::TENANT);
    }

    /**
     * Permisos de un rol del catálogo. Devuelve [] para un rol desconocido: el
     * llamador ya validó el nombre, y un rol vacío no otorga acceso a nada.
     *
     * @return string[]
     */
    public static function permisosDe(string $rol): array
    {
        return self::TENANT[$rol] ?? self::PLATAFORMA[$rol] ?? [];
    }

    /**
     * Todos los roles del catálogo (plataforma + tenant), listos para sembrar.
     *
     * @return array<string, string[]>
     */
    public static function todos(): array
    {
        return self::PLATAFORMA + self::TENANT;
    }
}
