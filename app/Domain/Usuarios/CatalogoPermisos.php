<?php

namespace App\Domain\Usuarios;

/**
 * Presentación del catálogo de permisos para el EDITOR DE ROLES: agrupa cada permiso
 * por módulo y le da una etiqueta y una descripción en español.
 *
 * Existe porque el editor no puede pintar 40 identificadores técnicos (`ordenes.cobrar`)
 * y esperar que un dueño de bar decida con eso. El backend es dueño de este texto —y no
 * el frontend— para que un permiso nuevo llegue a la UI con su etiqueta sin desplegar el
 * front, y para que no haya dos listas que se desincronicen (la lección de CatalogoRoles).
 *
 * Los permisos de PLATAFORMA (los del super_admin) NO aparecen aquí a propósito: no son
 * otorgables a un rol de tenant. Ver `asignables()`.
 */
class CatalogoPermisos
{
    /**
     * Módulos en el orden en que se muestran, con su etiqueta.
     *
     * @var array<string, string>
     */
    public const GRUPOS = [
        'venta' => 'Punto de venta',
        'caja' => 'Caja',
        'catalogo' => 'Catálogo',
        'inventario' => 'Inventario',
        'personal' => 'Personal y gobernanza',
        'reportes' => 'Reportes',
    ];

    /**
     * permiso => [grupo, etiqueta, descripción].
     *
     * La descripción se escribe en términos de lo que la persona PUEDE HACER, no del
     * endpoint que habilita: quien configura roles piensa en tareas, no en rutas.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const PERMISOS = [
        // --- Punto de venta ---
        'ordenes.crear' => ['venta', 'Abrir órdenes', 'Iniciar una cuenta en mesa, barra o para llevar.'],
        'ordenes.agregar_item' => ['venta', 'Agregar productos', 'Sumar productos a una orden abierta y enviar la comanda.'],
        'ordenes.cobrar' => ['venta', 'Cobrar', 'Registrar el pago de una orden y cerrarla.'],
        'ordenes.aplicar_descuento' => ['venta', 'Aplicar descuentos', 'Descontar sobre el total de una orden. Afecta el ingreso.'],
        'ordenes.cancelar_item' => ['venta', 'Cancelar productos', 'Quitar un producto ya comandado, sin pedir autorización.'],
        'ordenes.anular' => ['venta', 'Anular órdenes', 'Cancelar una orden completa, sin pedir autorización.'],
        'ordenes.reasignar' => ['venta', 'Reasignar mesero', 'Traspasar una orden abierta a otro miembro del personal.'],
        'mesas.ver' => ['venta', 'Ver mesas', 'Consultar el mapa de mesas para elegir dónde abrir la cuenta.'],
        'tickets.imprimir' => ['venta', 'Imprimir tickets', 'Emitir el ticket de una venta cobrada.'],
        'tickets.reimprimir' => ['venta', 'Reimprimir tickets', 'Volver a emitir un ticket ya impreso.'],

        // --- Caja ---
        'caja.ver' => ['caja', 'Ver estado de caja', 'Saber si hay caja abierta. Necesario para vender; no muestra montos.'],
        'caja.abrir' => ['caja', 'Abrir caja', 'Iniciar el turno con un fondo inicial.'],
        'caja.cerrar' => ['caja', 'Cerrar caja', 'Cerrar el turno, declarar el efectivo y ver el corte.'],

        // --- Catálogo ---
        'categorias.ver' => ['catalogo', 'Ver categorías', 'Necesario para que la rejilla del POS no aparezca vacía.'],
        'productos.ver' => ['catalogo', 'Ver productos', 'Necesario para elegir productos al vender.'],
        'categorias.gestionar' => ['catalogo', 'Gestionar categorías', 'Crear, editar y desactivar categorías.'],
        'productos.gestionar' => ['catalogo', 'Gestionar productos', 'Crear, editar y cambiar precios de productos.'],
        'recetas.gestionar' => ['catalogo', 'Gestionar recetas', 'Definir qué insumos descuenta cada producto al venderse.'],
        'mesas.gestionar' => ['catalogo', 'Gestionar mesas', 'Crear, editar y desactivar mesas.'],
        'impresoras.gestionar' => ['catalogo', 'Gestionar impresoras', 'Configurar las impresoras de tickets y comandas.'],

        // --- Inventario ---
        'insumos.gestionar' => ['inventario', 'Gestionar insumos', 'Crear y editar insumos y sus existencias mínimas.'],
        'proveedores.gestionar' => ['inventario', 'Gestionar proveedores', 'Alta y edición del padrón de proveedores.'],
        'unidades.gestionar' => ['inventario', 'Gestionar unidades', 'Definir unidades de medida propias del negocio.'],
        'inventario.merma' => ['inventario', 'Registrar mermas', 'Dar de baja producto por rotura, derrame o caducidad.'],
        'inventario.entrada' => ['inventario', 'Registrar entradas', 'Ingresar mercancía al inventario, sin pedir autorización.'],
        'inventario.ajustar' => ['inventario', 'Ajustar inventario', 'Corregir existencias a mano, sin pedir autorización.'],

        // --- Personal y gobernanza ---
        'usuarios.gestionar' => ['personal', 'Gestionar personal', 'Dar de alta, editar y desactivar cuentas del equipo.'],
        'usuarios.gestionar_admins' => ['personal', 'Gestionar administradores', 'Crear administradores y modificar los existentes. Otorga control total del negocio.'],
        'roles.gestionar' => ['personal', 'Gestionar roles', 'Crear y editar roles a medida. Quien lo tiene puede redefinir quién hace qué.'],
        'configuracion.editar' => ['personal', 'Editar configuración', 'Cambiar impuestos, impresión y datos del establecimiento.'],
        'autorizaciones.solicitar' => ['personal', 'Solicitar autorizaciones', 'Pedir el visto bueno de un superior para una operación sensible.'],
        'autorizaciones.aprobar' => ['personal', 'Aprobar autorizaciones', 'Autorizar o rechazar lo que solicita el personal.'],
        'auditoria.ver' => ['personal', 'Ver auditoría', 'Consultar la bitácora de todo lo que ocurre en el establecimiento.'],

        // --- Reportes ---
        'reportes.ver' => ['reportes', 'Ver todos los reportes', 'Acceso completo: ventas, margen, inventario y cancelaciones.'],
        'reportes.ver_limitado' => ['reportes', 'Ver reportes del turno', 'Solo el resumen de su propio turno, sin margen ni costos.'],
        'reportes.ver_dashboard' => ['reportes', 'Ver dashboard de decisiones', 'El panel de KPIs y gráficas generales del negocio. Reservado al dueño.'],
    ];

    /**
     * Permisos OTORGABLES a un rol de tenant: el catálogo completo menos los de
     * plataforma (super_admin). Un rol de establecimiento nunca debe poder
     * administrar otros establecimientos ni ver métricas globales.
     *
     * @return string[]
     */
    public static function asignables(): array
    {
        return array_values(array_diff(
            CatalogoRoles::PERMISOS,
            CatalogoRoles::PERMISOS_SUPER_ADMIN,
        ));
    }

    /**
     * El catálogo agrupado y listo para la UI del editor.
     *
     * @return array<int, array{grupo: string, etiqueta: string, permisos: array<int, array{nombre: string, etiqueta: string, descripcion: string}>}>
     */
    public static function agrupados(): array
    {
        $porGrupo = [];

        foreach (self::asignables() as $nombre) {
            // Un permiso sin ficha aún así se ofrece (con su nombre técnico) en vez de
            // desaparecer: es preferible una etiqueta fea a un permiso invisible.
            [$grupo, $etiqueta, $descripcion] = self::PERMISOS[$nombre] ?? ['otros', $nombre, ''];

            $porGrupo[$grupo][] = [
                'nombre' => $nombre,
                'etiqueta' => $etiqueta,
                'descripcion' => $descripcion,
            ];
        }

        $salida = [];

        foreach (self::GRUPOS as $grupo => $etiquetaGrupo) {
            if (isset($porGrupo[$grupo])) {
                $salida[] = ['grupo' => $grupo, 'etiqueta' => $etiquetaGrupo, 'permisos' => $porGrupo[$grupo]];
                unset($porGrupo[$grupo]);
            }
        }

        // Cualquier grupo no declarado en GRUPOS va al final, para que un permiso nuevo
        // sin clasificar siga siendo visible en el editor.
        foreach ($porGrupo as $grupo => $permisos) {
            $salida[] = ['grupo' => $grupo, 'etiqueta' => 'Otros', 'permisos' => $permisos];
        }

        return $salida;
    }
}
