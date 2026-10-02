# ESPECIFICACIÓN FUNCIONAL — MVP V1

**Producto:** SaaS POS Multi-Tenant para bares, cervecerías, cantinas, cafeterías y restaurantes pequeños
**Documento:** Especificación Funcional Oficial (previa a diseño técnico)
**Versión:** 1.1 (2026-08-15) · **Base:** DER V1.4 · **Alcance:** MVP V1 + evolución posterior (5 roles, roles a medida, override por PIN, terminal compartida)
**Excluye explícitamente:** código, arquitectura Laravel/React, diseño de API, gestión de clientes y suscripciones (V2).

> **Propósito.** Este documento define *qué* hace el sistema y bajo *qué reglas*, con el detalle suficiente para que un equipo pueda diseñar backend, frontend, API, permisos y plan de desarrollo sin volver a preguntar reglas de negocio. Donde una regla no está definida por el negocio, **no se inventa**: se registra como pregunta en la Fase 9.

---

## GLOSARIO

- **Establecimiento (tenant):** negocio cliente del SaaS. Unidad de aislamiento de datos (`id_establecimiento`).
- **Sesión de caja:** periodo entre una apertura y un cierre de caja. Solo una abierta por establecimiento.
- **Orden:** cuenta de consumo, de tipo *mesa* o *barra*. Estados: `abierta`, `pagada`, `anulada`.
- **Insumo:** artículo de inventario con stock (la fuente de verdad del stock es el ledger de movimientos).
- **Producto:** ítem vendible. Puede descontar inventario vía receta (BOM) o no descontar (`controla_inventario = false`).
- **Receta:** lista de insumos y cantidades que consume un producto al venderse.
- **Autorización:** solicitud de una operación sensible que un ADMIN debe aprobar antes de ejecutarse.
- **Arqueo:** conteo físico de efectivo al cierre de caja, comparado contra el monto del sistema.
- **Merma:** salida de inventario por pérdida, rotura o consumo interno.

---

# FASE 1 — MÓDULOS DEL SISTEMA

Se identifican **15 módulos funcionales**. Cada uno indica objetivo, alcance y dependencias.

### M01 · Autenticación y Control de Acceso
- **Objetivo:** autenticar usuarios y resolver el establecimiento (tenant) y rol con que operan.
- **Alcance:** login, logout, recuperación de sesión, resolución de tenant, control de rol en cada acción.
- **Dependencias:** Usuarios y Roles (M04).

### M02 · Administración de Plataforma (SUPER_ADMIN)
- **Objetivo:** gestionar los establecimientos del SaaS y la visión global.
- **Alcance:** alta/edición/activación/desactivación de establecimientos, asignación de administradores, métricas y auditoría global.
- **Dependencias:** Establecimientos, Usuarios, Auditoría.

### M03 · Configuración del Establecimiento
- **Objetivo:** parametrizar datos operativos y de impresión del negocio.
- **Alcance:** datos de ticket (nombre comercial, teléfono y dirección de ticket), impresión automática, stock mínimo global, impuesto, **modo terminal compartida** (varios meseros en una tablet) y auto-bloqueo de la terminal.
- **Dependencias:** Establecimientos (M02).

### M04 · Usuarios y Roles
- **Objetivo:** administrar el personal del establecimiento y sus permisos por rol.
- **Alcance:** CRUD de usuarios del establecimiento, asignación de rol (admin, gerente, operador, mesero **o cualquier rol a medida del tenant**), activación/desactivación.
- **Dependencias:** Autenticación (M01), Auditoría.

### M05 · Categorías de Producto
- **Objetivo:** organizar el catálogo para la venta.
- **Alcance:** CRUD de categorías, orden de despliegue, activación.
- **Dependencias:** ninguna (catálogo base del establecimiento).

### M06 · Productos
- **Objetivo:** definir los ítems vendibles y su precio/costo de referencia.
- **Alcance:** CRUD de productos, asignación de categoría, disponibilidad, bandera de control de inventario, vínculo a recetas.
- **Dependencias:** Categorías (M05), Recetas (M07), Inventario (M08).

### M07 · Recetas (BOM)
- **Objetivo:** declarar qué insumos y cantidades consume cada producto al venderse.
- **Alcance:** alta/edición/baja de líneas receta (producto ↔ insumo ↔ cantidad).
- **Dependencias:** Productos (M06), Inventario/Insumos (M08).

### M08 · Inventario (Insumos, Proveedores, Unidades, Movimientos)
- **Objetivo:** controlar existencias y trazar todos sus movimientos.
- **Alcance:** CRUD de insumos, proveedores y unidades de medida; registro de entradas, ajustes, mermas, roturas, consumo interno; descuento automático por venta; alertas de stock bajo.
- **Dependencias:** Autorizaciones (M13), Auditoría (M14), Órdenes (M11) para el descuento por venta.

### M09 · Mesas
- **Objetivo:** representar las posiciones de consumo y su disponibilidad.
- **Alcance:** CRUD de mesas, estado derivado (libre/ocupada), liberación automática al pagar.
- **Dependencias:** Órdenes (M11).

### M10 · Caja
- **Objetivo:** controlar el ciclo de apertura, operación y cierre con arqueo.
- **Alcance:** apertura con monto inicial, una sola caja abierta por establecimiento, cierre con conteo y diferencia, bloqueo de venta sin caja.
- **Dependencias:** Usuarios (M04), Órdenes (M11), Pagos (M12), Auditoría (M14).

### M11 · Órdenes (Venta)
- **Objetivo:** gestionar el ciclo de vida de una cuenta de consumo.
- **Atribución del mesero (2026-08-15):** el sistema soporta **dos modos de operación** sin duplicar lógica. Con **dispositivo por mesero**, la cuenta que abre la orden ya es la persona. Con **terminal compartida**, el mesero teclea su PIN, queda activo unos minutos y **vuelve a firmar al cobrar**; su identidad se guarda en `ordenes.id_mesero` / `pagos.id_mesero`. De quién es la venta se responde siempre con `COALESCE(id_mesero, id_usuario)`. El PIN de mesero **identifica, nunca autoriza**: no emite sesión ni concede permisos, y el flujo de override lo rechaza.
- **Alcance:** crear orden (mesa/barra), agregar/quitar ítems, cálculo de totales, cancelación de ítems (con autorización), anulación de orden (con autorización), cierre al pagar.
- **Dependencias:** Caja (M10), Mesas (M09), Productos (M06), Inventario (M08), Autorizaciones (M13), Pagos (M12).

### M12 · Pagos
- **Objetivo:** registrar el cobro de una orden y disparar su cierre.
- **Alcance:** pago simple y dividido, tipos de pago (efectivo/tarjeta/transferencia), referencia, cierre de orden e impresión automática. (La propina **no se registra** en V1 — decisión P9.)
- **Dependencias:** Órdenes (M11), Caja (M10), Tickets (M12bis), Mesas (M09).

### M13 · Impresión y Tickets / Impresoras
- **Objetivo:** generar e imprimir comandas y tickets de cobro, y administrar impresoras.
- **Alcance:** CRUD de impresoras (ticket/barra/cocina/admin), generación de comanda y ticket de cobro, reimpresión, contenido en JSON.
- **Dependencias:** Configuración (M03), Órdenes (M11), Pagos (M12).

### M14 · Autorizaciones
- **Objetivo:** materializar el flujo "operador solicita → admin autoriza → sistema ejecuta → sistema audita".
- **Alcance:** creación de solicitudes (cancelar ítem, anular orden, ajustar/incrementar stock), aprobación/rechazo por ADMIN, vínculo a la operación ejecutada.
- **Dependencias:** Órdenes (M11), Inventario (M08), Auditoría (M14).

### M15 · Auditoría
- **Objetivo:** registrar de forma visible las acciones sensibles del sistema.
- **Alcance:** bitácora de apertura/cierre de caja, creación de órdenes, cancelaciones/anulaciones, ajustes y mermas, alta/baja de usuarios, autorizaciones; consulta filtrable por ADMIN y global por SUPER_ADMIN.
- **Dependencias:** transversal a todos los módulos.

### M16 · Reportes y Dashboard
- **Objetivo:** entregar la información operativa y de gestión.
- **Alcance:** dashboard del día, reportes de ventas (diario/semanal/mensual/anual), **ventas por mesero**, inventario (stock, stock bajo, movimientos, mermas), caja (aperturas/cierres/diferencias), cancelaciones, medios de pago, margen, consumo de insumos; exportación a PDF y Excel.
- **Dependencias:** consume datos de todos los módulos operativos.

> Nota: se listan 16 identificadores porque Impresión/Tickets e Impresoras comparten módulo (M13); el conteo funcional es de 15 módulos.

---

# FASE 2 — FUNCIONALIDADES POR MÓDULO

Para cada módulo: objetivo, funcionalidades, reglas de negocio, restricciones y casos especiales.

## M01 · Autenticación y Control de Acceso
- **Objetivo:** garantizar que cada usuario opere solo en su establecimiento y con su rol.
- **Funcionalidades:** iniciar sesión (email/usuario + contraseña), cerrar sesión, mantener sesión activa, recuperar contraseña (vía correo).
- **Reglas:** el usuario solo accede a datos de su `id_establecimiento`; el SUPER_ADMIN no pertenece a un establecimiento; un usuario `activo = false` no puede iniciar sesión.
- **Restricciones:** credenciales únicas por establecimiento (email/usuario).
- **Casos especiales:** establecimiento desactivado → sus usuarios no pueden iniciar sesión (excepto consulta de SUPER_ADMIN).

## M02 · Administración de Plataforma (SUPER_ADMIN)
- **Objetivo:** operar el negocio SaaS.
- **Funcionalidades:** crear/editar establecimiento; activar/desactivar; asignar el primer ADMIN; ver métricas globales (establecimientos activos, ventas agregadas); ver auditoría global.
- **Reglas:** los establecimientos no se eliminan, se desactivan; al crear un establecimiento se siembra su configuración por defecto.
- **Restricciones:** solo SUPER_ADMIN accede a este módulo.
- **Casos especiales:** desactivar un establecimiento con caja abierta (ver Fase 9, pendiente).

## M03 · Configuración del Establecimiento
- **Objetivo:** parametrizar la operación e impresión.
- **Funcionalidades:** editar nombre comercial, teléfono y dirección de ticket; activar/desactivar impresión automática; definir stock mínimo global por defecto.
- **Reglas:** relación 1:1 con el establecimiento; los datos de ticket pueden diferir de los datos legales/contacto.
- **Restricciones:** solo ADMIN edita.
- **Casos especiales:** si no hay configuración cargada, se usan valores por defecto.

## M04 · Usuarios y Roles
- **Objetivo:** administrar el personal.
- **Funcionalidades:** listar, crear, editar, activar/desactivar usuarios; asignar rol. Los roles asignables se resuelven en runtime (`RolesAsignables::para($actor)`): incluyen los presets del tenant y sus roles a medida, y **excluyen `admin` para quien no tenga `usuarios.gestionar_admins`**.
- **Reglas:** un ADMIN solo gestiona usuarios de su establecimiento; no se elimina físicamente (soft delete); debe existir al menos un ADMIN activo por establecimiento.
- **Restricciones:** el OPERADOR no accede a este módulo; un usuario no puede desactivarse a sí mismo si es el único ADMIN.
- **Casos especiales:** degradar de rol al **último admin activo** → bloqueada (el tenant quedaría sin dueño). Un gerente que intenta crear/editar/degradar a un admin → 422 en el Form Request o 403 en la policy, según el flanco.

## M05 · Categorías de Producto
- **Objetivo:** organizar el catálogo.
- **Funcionalidades:** listar, crear, editar, activar/desactivar, ordenar (orden_display).
- **Reglas:** no se elimina físicamente; una categoría con productos activos no puede desactivarse sin advertencia.
- **Restricciones:** solo ADMIN administra.
- **Casos especiales:** categoría inactiva no se muestra en la pantalla de venta.

## M06 · Productos
- **Objetivo:** definir el menú vendible.
- **Funcionalidades:** listar, crear, editar, activar/desactivar (disponible), asignar categoría, fijar precio_venta y costo_referencia, marcar control de inventario, gestionar receta.
- **Reglas:** un producto con `controla_inventario = true` descuenta stock vía receta al venderse; sin receta y con control de inventario activo → no puede venderse (ver Fase 9). El producto no se elimina, se desactiva.
- **Restricciones:** solo ADMIN administra; el precio no puede ser negativo.
- **Casos especiales:** producto sin receta y `controla_inventario = false` → vendible sin afectar inventario (p. ej. servicios).

## M07 · Recetas (BOM)
- **Objetivo:** declarar el consumo de insumos por producto.
- **Funcionalidades:** agregar, editar, quitar líneas (insumo + cantidad).
- **Reglas:** combinación producto+insumo única; cantidades > 0; un producto de stock directo (cerveza embotellada) se modela como receta de un solo insumo (1 unidad).
- **Restricciones:** solo ADMIN administra.
- **Casos especiales:** cambiar la receta no afecta órdenes ya cobradas.

## M08 · Inventario
- **Objetivo:** controlar existencias con trazabilidad total.
- **Funcionalidades:** CRUD insumos/proveedores/unidades; registrar entrada (compra), ajuste, merma, rotura, consumo interno; descuento automático por venta; alerta de stock bajo; consulta de kardex por insumo.
- **Reglas:** la fuente de verdad del stock es el ledger `movimientos_inventario`; `insumos.stock_actual` es un cache que se reconcilia con cada movimiento; **incrementar stock** (entrada/ajuste positivo) y **ajustar/anular** requieren autorización si lo solicita un OPERADOR; toda merma queda auditada.
- **Restricciones:** el OPERADOR puede registrar mermas pero **no** incrementar stock; cantidades > 0; un movimiento no se edita ni borra (se corrige con un movimiento inverso).
- **Casos especiales:** venta de producto cuyo insumo no alcanza el stock (ver Fase 9, política de stock negativo).

## M09 · Mesas
- **Objetivo:** representar posiciones y disponibilidad.
- **Funcionalidades:** listar, crear, editar, activar/desactivar mesas; visualizar estado (libre/ocupada).
- **Reglas:** número de mesa único por establecimiento; el estado se **deriva** de la existencia de una orden abierta; al pagar la orden, la mesa se libera automáticamente.
- **Restricciones:** no se puede desactivar una mesa con orden abierta.
- **Casos especiales:** órdenes de barra/llevar no ocupan mesa.

## M10 · Caja
- **Objetivo:** controlar el efectivo y habilitar la venta.
- **Funcionalidades:** abrir caja con monto inicial; operar; cerrar con conteo (arqueo) y cálculo de diferencia; consultar caja actual e histórico.
- **Reglas:** **solo una caja abierta por establecimiento**; no se puede vender sin caja abierta; no se puede cerrar caja con órdenes abiertas; la caja es del establecimiento (compartida entre operadores del turno).
- **Restricciones:** una caja cerrada no se reabre.
- **Casos especiales:** cierre con diferencia (faltante/sobrante) → se registra y audita (ver Fase 9 sobre autorización de diferencias).

## M11 · Órdenes (Venta)
- **Objetivo:** gestionar la cuenta de consumo.
- **Funcionalidades:** crear orden (mesa o barra); agregar ítems con cantidad; modificar cantidad de ítems no enviados; cancelar ítem (con autorización); calcular subtotal/impuesto/descuento/total; anular orden (con autorización); enviar comanda; cerrar al pagar.
- **Reglas:** requiere caja abierta; una mesa solo una orden abierta; el folio es único por establecimiento; una orden pagada no se reabre; al cancelar un ítem que descontó inventario, se revierte el stock (ver Fase 9 sobre momento del descuento).
- **Restricciones:** no se agregan ítems a una orden pagada o anulada; cantidades > 0.
- **Casos especiales:** anulación de orden con productos consumidos = pérdida registrada (walkout), nunca borrado.

## M12 · Pagos
- **Objetivo:** registrar el cobro y cerrar la orden.
- **Funcionalidades:** registrar uno o varios pagos por orden (pago dividido); seleccionar tipo de pago; capturar referencia; al completar el cobro: cerrar orden, liberar mesa, imprimir ticket. (La propina **no se registra** en V1 — decisión P9.)
- **Reglas:** la suma de pagos debe cubrir el total de la orden; la orden se cierra solo cuando el saldo llega a cero; el ticket se imprime automáticamente al cerrar.
- **Restricciones:** no se cobra una orden ya pagada/anulada; monto > 0.
- **Casos especiales:** pago dividido por monto o por partes iguales (ver Fase 9 sobre división por ítems); sobrepago en efectivo → cálculo de cambio (no se almacena como pago).

## M13 · Impresión y Tickets / Impresoras
- **Objetivo:** producir comandas y tickets.
- **Funcionalidades:** CRUD impresoras; generar comanda (al enviar ítems); generar ticket de cobro (al pagar); reimprimir ticket; almacenar contenido en JSON.
- **Reglas:** el establecimiento puede tener múltiples impresoras por tipo; la impresión automática depende de la configuración; cada impresión de cobro genera un registro de ticket.
- **Restricciones:** no se imprime ticket de cobro de una orden no pagada.
- **Casos especiales:** reimpresión (ver Fase 9 sobre si requiere permiso/autorización y si se audita).

## M14 · Autorizaciones
- **Objetivo:** controlar operaciones sensibles.
- **Funcionalidades:** el OPERADOR crea una solicitud (tipo, entidad, motivo); el ADMIN aprueba o rechaza; al aprobar, el sistema ejecuta y enlaza la operación; toda resolución se audita.
- **Reglas:** una operación sensible no se ejecuta sin autorización aprobada; el ADMIN puede ejecutar directamente (autoaprobación implícita) las operaciones que él mismo realiza.
- **Restricciones:** el OPERADOR no aprueba; una solicitud resuelta no cambia de estado.
- **Casos especiales:** solicitud caducada/rechazada → la operación no se ejecuta.

## M15 · Auditoría
- **Objetivo:** trazabilidad visible.
- **Funcionalidades:** registro automático de acciones sensibles con datos antes/después; consulta filtrable (fecha, usuario, acción, entidad); auditoría global para SUPER_ADMIN.
- **Reglas:** la auditoría es append-only (no se edita ni borra); cada registro guarda usuario, acción, entidad y momento.
- **Restricciones:** el OPERADOR no consulta auditoría.
- **Casos especiales:** acciones del SUPER_ADMIN se registran sin `id_establecimiento`.

## M16 · Reportes y Dashboard
- **Objetivo:** información operativa y de gestión.
- **Funcionalidades:** dashboard del día (ventas, órdenes abiertas/cerradas, productos vendidos); ventas por periodo; inventario (stock, stock bajo, movimientos, mermas); caja (aperturas, cierres, diferencias); cancelaciones (usuario, motivo, fecha); exportación PDF/Excel.
- **Reglas:** todo reporte se filtra por `id_establecimiento`; los rangos de fecha respetan la zona horaria del establecimiento.
- **Restricciones:** el OPERADOR ve un subconjunto (su operación del turno); el ADMIN ve todo el establecimiento.
- **Casos especiales:** reporte de utilidad usa `costo_referencia` y/o costo de insumos (ver Fase 9 sobre fuente de costo en el reporte de margen).
- **Ventas por mesero (2026-08-15):** agrupa los pagos del rango por **mesero efectivo** — `COALESCE(id_mesero, id_usuario)` — de modo que el número es correcto tanto si cada mesero usa su propio dispositivo como si comparten una tablet con PIN. Es reporte de gestión (ADMIN/GERENTE): comparar el desempeño entre compañeros no es información de turno. Se calcula sobre **pagos**, no sobre órdenes, porque quien abre la mesa no siempre es quien cobra.

---

# FASE 3 — PANTALLAS

Por módulo: pantallas, objetivo, acciones y componentes visibles.

## M01 Autenticación
- **Login** — objetivo: autenticar. Acciones: iniciar sesión, recuperar contraseña. Componentes: campo usuario/email, campo contraseña, botón entrar, enlace "olvidé mi contraseña".
- **Recuperar contraseña** — objetivo: restablecer acceso. Acciones: enviar enlace. Componentes: campo email, botón enviar.

## M02 Administración de Plataforma (SUPER_ADMIN)
- **Listado de establecimientos** — acciones: buscar, crear, activar/desactivar, ver detalle. Componentes: tabla (nombre, estado, fecha alta), filtros, botón crear.
- **Crear / Editar establecimiento** — acciones: guardar, asignar admin. Componentes: formulario (datos fiscales y de contacto), selector de admin.
- **Detalle de establecimiento** — acciones: editar, ver métricas. Componentes: ficha de datos, indicadores.
- **Métricas globales** — Componentes: tarjetas (activos, ventas agregadas), gráficas.
- **Auditoría global** — acciones: filtrar. Componentes: tabla de eventos, filtros.

## M03 Configuración del Establecimiento
- **Configuración** — acciones: editar, guardar. Componentes: formulario (nombre comercial, teléfono/dirección de ticket, toggle impresión automática, stock mínimo global).

## M04 Usuarios
- **Listado** — acciones: buscar, crear, editar, activar/desactivar. Componentes: tabla (nombre, rol, estado).
- **Crear / Editar usuario** — acciones: guardar. Componentes: formulario (nombre, email/usuario, contraseña, selector de rol, activo).
- **Detalle de usuario** — Componentes: ficha + historial de acciones (auditoría del usuario).

## M05 Categorías
- **Listado** — acciones: crear, editar, reordenar, activar/desactivar. Componentes: tabla ordenable.
- **Crear / Editar** — Componentes: formulario (nombre, orden, activo).

## M06 Productos
- **Listado** — acciones: buscar, filtrar por categoría, crear, editar, activar/desactivar. Componentes: tabla (nombre, categoría, precio, disponible).
- **Crear / Editar** — Componentes: formulario (nombre, descripción, categoría, precio_venta, costo_referencia, controla_inventario, disponible, sku).
- **Detalle** — Componentes: ficha + receta asociada.
- **Editor de receta** — acciones: agregar/quitar insumo, fijar cantidad. Componentes: lista de líneas (insumo, cantidad, unidad), buscador de insumos.

## M07 Recetas
- (Integrado en Producto → Editor de receta).

## M08 Inventario
- **Insumos – Listado** — acciones: crear, editar, activar/desactivar, ver kardex. Componentes: tabla (insumo, stock_actual, stock_minimo, unidad, alerta de stock bajo).
- **Insumo – Crear / Editar** — Componentes: formulario (nombre, unidad, proveedor, stock_minimo, costo_unitario).
- **Kardex del insumo** — Componentes: tabla de movimientos (tipo, cantidad, resultante, fecha, usuario, motivo).
- **Registrar movimiento** — acciones: registrar entrada/ajuste/merma/rotura/consumo. Componentes: selector de tipo, cantidad, costo, motivo (entradas/ajustes pueden disparar autorización).
- **Proveedores – Listado / Crear / Editar.**
- **Unidades de medida – Listado / Crear / Editar** (catálogo global, solo lectura para ADMIN — ver Fase 9).

## M09 Mesas
- **Mapa / Listado de mesas** — acciones: abrir orden, ver orden, crear/editar mesa. Componentes: cuadrícula con estado por color (libre/ocupada), capacidad, zona.
- **Crear / Editar mesa** — Componentes: formulario (número, nombre, zona, capacidad, activa).

## M10 Caja
- **Estado de caja** — acciones: abrir caja / cerrar caja. Componentes: indicador abierta/cerrada, monto inicial, ventas acumuladas.
- **Abrir caja** — Componentes: campo monto inicial, botón abrir.
- **Cerrar caja (arqueo)** — Componentes: monto del sistema (calculado), campo monto contado, diferencia (auto), botón cerrar.
- **Histórico de cajas** — Componentes: tabla (apertura, cierre, usuario, diferencia).

## M11 Órdenes / Venta
- **Pantalla de venta (POS)** — acciones: seleccionar mesa o barra, agregar productos, modificar cantidad, solicitar cancelación de ítem, enviar comanda, cobrar, anular orden. Componentes: catálogo por categoría, ticket en curso (ítems, subtotal, total), botones de acción, buscador de producto.
- **Listado de órdenes** — acciones: filtrar por estado, ver detalle. Componentes: tabla (folio, mesa/barra, estado, total, hora).
- **Detalle de orden** — Componentes: ítems, totales, pagos, historial.

## M12 Pagos
- **Cobro** — acciones: seleccionar tipo de pago, capturar monto/referencia, registrar pago, dividir pago, finalizar. Componentes: total a pagar, saldo, lista de pagos aplicados, calculadora de cambio. (Sin captura de propina en V1 — decisión P9.)

## M13 Impresión / Impresoras
- **Impresoras – Listado / Crear / Editar** — Componentes: formulario (nombre, tipo, conexión, activa).
- **Vista previa de ticket / comanda** — acciones: imprimir, reimprimir. Componentes: render del contenido.

## M14 Autorizaciones
- **Bandeja de autorizaciones (ADMIN)** — acciones: aprobar, rechazar. Componentes: tabla (tipo, solicitante, entidad, motivo, estado), detalle.
- **Solicitud (OPERADOR)** — acciones: enviar solicitud. Componentes: formulario (tipo, motivo) lanzado desde la operación.

## M15 Auditoría
- **Bitácora** — acciones: filtrar por fecha/usuario/acción/entidad, ver detalle (antes/después). Componentes: tabla de eventos, panel de detalle.

## M16 Reportes / Dashboard
- **Dashboard** — Componentes: tarjetas (ventas del día, órdenes abiertas/cerradas), top de productos vendidos.
- **Reporte de ventas** — acciones: elegir periodo, exportar. Componentes: tabla/gráfica, botones PDF/Excel.
- **Reporte de inventario** — Componentes: stock actual, stock bajo, movimientos, mermas.
- **Reporte de caja** — Componentes: aperturas, cierres, diferencias.
- **Reporte de cancelaciones** — Componentes: tabla (usuario, motivo, fecha, entidad).

---

# FASE 4 — VALIDACIONES

Por pantalla: validaciones, restricciones, mensajes de error y casos límite.

## Login
- Usuario y contraseña obligatorios → "Ingresa tu usuario y contraseña."
- Credenciales inválidas → "Usuario o contraseña incorrectos."
- Usuario inactivo → "Tu cuenta está desactivada. Contacta al administrador."
- Establecimiento desactivado → "El establecimiento no está disponible."
- Caso límite: múltiples intentos fallidos → bloqueo temporal (ver Fase 9 sobre política de intentos).

## Crear/Editar Establecimiento (SUPER_ADMIN)
- Nombre obligatorio; RFC con formato válido si se captura → "RFC inválido."
- Email con formato válido → "Correo inválido."
- Debe asignarse un ADMIN al crear → "Asigna un administrador."
- Caso límite: desactivar establecimiento con caja abierta → advertencia/bloqueo (Fase 9).

## Usuario (Crear/Editar)
- Nombre, email/usuario y rol obligatorios.
- Email/usuario único por establecimiento → "Ya existe un usuario con ese correo."
- Contraseña mínima (longitud/robustez por definir, Fase 9) → "La contraseña no cumple los requisitos."
- No desactivar al único ADMIN activo → "Debe existir al menos un administrador activo."

## Categoría
- Nombre obligatorio y único por establecimiento → "Ya existe una categoría con ese nombre."
- Caso límite: desactivar categoría con productos activos → "La categoría tiene productos activos. ¿Desactivar de todos modos?"

## Producto
- Nombre obligatorio; precio_venta ≥ 0; costo_referencia ≥ 0.
- Categoría obligatoria → "Selecciona una categoría."
- Si `controla_inventario = true` y no tiene receta → advertencia "El producto no podrá venderse hasta definir su receta" (Fase 9 confirma si bloquea venta).
- Caso límite: desactivar producto presente en órdenes abiertas → permitido (no afecta órdenes en curso) pero deja de ofrecerse.

## Receta
- Insumo y cantidad obligatorios; cantidad > 0.
- Insumo no repetido en el mismo producto → "Ese insumo ya está en la receta."

## Insumo / Movimiento de inventario
- Nombre, unidad obligatorios; stock_minimo ≥ 0; costo_unitario ≥ 0.
- Movimiento: tipo y cantidad obligatorios; cantidad > 0; motivo obligatorio en merma/ajuste → "Indica el motivo."
- Entrada/ajuste positivo solicitado por OPERADOR → genera autorización; no se aplica hasta aprobarse.
- Caso límite: salida/merma mayor al stock disponible → según política (Fase 9).

## Mesa
- Número obligatorio y único por establecimiento → "Ya existe una mesa con ese número."
- Capacidad > 0.
- No desactivar mesa con orden abierta → "La mesa tiene una orden abierta."

## Abrir Caja
- Monto inicial ≥ 0 obligatorio.
- Ya existe caja abierta → "Ya hay una caja abierta."

## Cerrar Caja
- Monto contado ≥ 0 obligatorio.
- Existen órdenes abiertas → "No puedes cerrar la caja con órdenes abiertas."
- Diferencia ≠ 0 → se muestra y registra; (Fase 9 define si requiere motivo/autorización).

## Venta / Orden
- No hay caja abierta → "Abre la caja para poder vender."
- Crear orden de mesa ya ocupada → "La mesa ya tiene una orden abierta."
- Agregar ítem con cantidad ≤ 0 → "Cantidad inválida."
- Producto no disponible → "El producto no está disponible."
- Insuficiencia de stock (si la política bloquea) → "Stock insuficiente para [insumo]."
- Cancelar ítem sin autorización → "Esta acción requiere autorización del administrador."
- Anular orden sin autorización → mismo mensaje.

## Pago
- Total > 0; monto de pago > 0.
- Suma de pagos < total al finalizar → "El pago no cubre el total."
- Cobrar orden ya pagada → "La orden ya fue pagada."
- Caso límite: sobrepago en efectivo → mostrar cambio; sobrepago en tarjeta/transferencia → no permitido.

## Impresora
- Nombre y tipo obligatorios.
- Conexión con formato válido (IP/cola) → "Conexión inválida."

## Autorización
- Motivo obligatorio en la solicitud → "Indica el motivo."
- Aprobar/rechazar una solicitud ya resuelta → "La solicitud ya fue resuelta."

## Reportes
- Rango de fechas válido (inicio ≤ fin) → "Rango de fechas inválido."
- Exportación sin datos → "No hay datos para el periodo seleccionado."

---

# FASE 5 — CASOS DE USO

Formato: Nombre · Actor · Descripción · Precondiciones · Flujo principal · Flujos alternativos · Excepciones · Postcondiciones.

## CU-01 · Iniciar sesión
- **Actor:** cualquier usuario.
- **Descripción:** autenticar y resolver establecimiento/rol.
- **Precondiciones:** usuario activo; establecimiento activo (salvo SUPER_ADMIN).
- **Flujo principal:** 1) ingresa credenciales; 2) sistema valida; 3) crea sesión; 4) redirige según rol.
- **Alternativos:** A1 recuperar contraseña.
- **Excepciones:** credenciales inválidas; usuario/establecimiento inactivo.
- **Postcondiciones:** sesión activa con contexto de tenant y rol.

## CU-02 · Crear establecimiento
- **Actor:** SUPER_ADMIN.
- **Descripción:** alta de un nuevo negocio cliente.
- **Precondiciones:** sesión SUPER_ADMIN.
- **Flujo principal:** 1) captura datos; 2) asigna ADMIN inicial; 3) guarda; 4) sistema siembra configuración por defecto y roles; 5) audita.
- **Alternativos:** A1 guardar como inactivo.
- **Excepciones:** datos inválidos; admin no asignado.
- **Postcondiciones:** establecimiento y su ADMIN disponibles.

## CU-03 · Crear usuario
- **Actor:** ADMIN.
- **Descripción:** alta de personal del establecimiento con cualquier rol asignable (admin, gerente, operador, mesero o un rol a medida del tenant).
- **Precondiciones:** sesión ADMIN.
- **Flujo principal:** 1) captura datos y rol; 2) valida unicidad; 3) guarda; 4) audita.
- **Excepciones:** email/usuario duplicado.
- **Postcondiciones:** usuario activo.

## CU-04 · Crear/editar producto y receta
- **Actor:** ADMIN.
- **Descripción:** definir ítem vendible y su consumo de insumos.
- **Precondiciones:** existen categorías; existen insumos (si lleva receta).
- **Flujo principal:** 1) captura producto; 2) define receta (insumo+cantidad); 3) guarda; 4) audita.
- **Alternativos:** A1 producto sin receta y sin control de inventario.
- **Excepciones:** precio negativo; insumo repetido.
- **Postcondiciones:** producto disponible para venta.

## CU-05 · Abrir caja
- **Actor:** OPERADOR o ADMIN.
- **Descripción:** iniciar la sesión de caja del establecimiento.
- **Precondiciones:** no hay caja abierta.
- **Flujo principal:** 1) captura monto inicial; 2) sistema crea sesión `abierta`; 3) audita.
- **Excepciones:** ya existe caja abierta.
- **Postcondiciones:** ventas habilitadas.

## CU-06 · Cerrar caja
- **Actor:** OPERADOR o ADMIN.
- **Descripción:** cerrar la sesión con arqueo.
- **Precondiciones:** caja abierta; sin órdenes abiertas.
- **Flujo principal:** 1) sistema calcula monto del sistema; 2) usuario captura monto contado; 3) sistema calcula diferencia; 4) cierra sesión; 5) audita.
- **Excepciones:** existen órdenes abiertas → bloqueo.
- **Postcondiciones:** caja cerrada; venta deshabilitada hasta nueva apertura.

## CU-07 · Crear orden
- **Actor:** OPERADOR o ADMIN.
- **Descripción:** abrir una cuenta de mesa o barra.
- **Precondiciones:** caja abierta; mesa libre (si es de mesa).
- **Flujo principal:** 1) selecciona tipo (mesa/barra); 2) si mesa, selecciona mesa libre; 3) sistema genera folio y crea orden `abierta`; 4) audita.
- **Excepciones:** mesa ya ocupada; sin caja abierta.
- **Postcondiciones:** orden abierta lista para recibir ítems.

## CU-08 · Agregar producto a la orden
- **Actor:** OPERADOR o ADMIN.
- **Descripción:** añadir ítems a una orden abierta.
- **Precondiciones:** orden abierta.
- **Flujo principal:** 1) selecciona producto y cantidad; 2) sistema agrega línea, recalcula totales; 3) (según política) descuenta inventario y registra movimiento.
- **Alternativos:** A1 modificar cantidad de un ítem aún no enviado.
- **Excepciones:** producto no disponible; stock insuficiente (según política).
- **Postcondiciones:** orden actualizada.

## CU-09 · Enviar comanda
- **Actor:** OPERADOR o ADMIN.
- **Descripción:** imprimir/encolar la comanda hacia barra/cocina.
- **Precondiciones:** orden con ítems.
- **Flujo principal:** 1) confirma envío; 2) sistema genera comanda y la envía a la impresora del tipo correspondiente; 3) marca ítems como enviados.
- **Excepciones:** sin impresora configurada → genera comanda en pantalla.
- **Postcondiciones:** comanda emitida.

## CU-10 · Solicitar y autorizar cancelación de ítem
- **Actor:** OPERADOR (solicita), ADMIN (autoriza).
- **Descripción:** cancelar un ítem de una orden abierta.
- **Precondiciones:** orden abierta con el ítem.
- **Flujo principal:** 1) OPERADOR solicita cancelación con motivo; 2) sistema crea autorización `pendiente`; 3) ADMIN aprueba; 4) sistema marca el ítem `cancelado`, fija `cancelado_at`, revierte inventario si aplica, recalcula totales; 5) audita.
- **Alternativos:** A1 el ADMIN cancela directamente (sin solicitud).
- **Excepciones:** ADMIN rechaza → el ítem permanece.
- **Postcondiciones:** ítem cancelado y trazado a la autorización.

## CU-11 · Anular orden
- **Actor:** OPERADOR (solicita), ADMIN (autoriza).
- **Descripción:** cerrar una orden sin pago (error/walkout).
- **Precondiciones:** orden abierta.
- **Flujo principal:** 1) solicitud con motivo; 2) ADMIN autoriza; 3) sistema marca orden `anulada`, revierte inventario si aplica, libera la mesa; 4) audita.
- **Excepciones:** rechazo de autorización.
- **Postcondiciones:** orden anulada (no reabrible); mesa liberada.

## CU-12 · Cobrar orden (pago simple)
- **Actor:** OPERADOR o ADMIN.
- **Descripción:** registrar el pago total y cerrar.
- **Precondiciones:** orden abierta con saldo > 0; caja abierta.
- **Flujo principal:** 1) selecciona tipo de pago; 2) captura monto (≥ total) y referencia; 3) sistema registra pago; 4) saldo = 0 → cierra orden, libera mesa, imprime ticket; 5) audita. (Sin propina en V1 — decisión P9.)
- **Excepciones:** monto insuficiente; orden ya pagada.
- **Postcondiciones:** orden pagada; ticket emitido; mesa libre.

## CU-13 · Cobrar orden (pago dividido)
- **Actor:** OPERADOR o ADMIN.
- **Descripción:** cubrir el total con varios pagos.
- **Precondiciones:** orden abierta con saldo > 0.
- **Flujo principal:** 1) registra pago parcial (tipo, monto); 2) sistema reduce saldo; 3) repite hasta saldo = 0; 4) cierra orden, libera mesa, imprime ticket.
- **Alternativos:** A1 distintos tipos de pago por parte.
- **Excepciones:** suma final < total.
- **Postcondiciones:** orden pagada con múltiples pagos.

## CU-14 · Imprimir / reimprimir ticket
- **Actor:** OPERADOR o ADMIN.
- **Descripción:** emitir o reemitir el ticket de cobro.
- **Precondiciones:** orden pagada.
- **Flujo principal:** 1) solicita impresión; 2) sistema genera contenido y lo envía a impresora; 3) registra ticket.
- **Alternativos:** A1 reimpresión (sujeta a permiso, Fase 9).
- **Excepciones:** orden no pagada.
- **Postcondiciones:** ticket emitido y registrado.

## CU-15 · Registrar merma
- **Actor:** OPERADOR o ADMIN.
- **Descripción:** registrar pérdida/rotura/consumo interno de inventario.
- **Precondiciones:** insumo existente.
- **Flujo principal:** 1) selecciona insumo, tipo (merma/rotura/consumo), cantidad y motivo; 2) sistema registra movimiento de salida, actualiza stock; 3) audita.
- **Excepciones:** cantidad mayor a stock (según política).
- **Postcondiciones:** stock reducido y trazado.

## CU-16 · Registrar entrada / ajuste de inventario
- **Actor:** ADMIN (directo) u OPERADOR (vía autorización).
- **Descripción:** incrementar o corregir stock.
- **Precondiciones:** insumo existente.
- **Flujo principal:** 1) selecciona insumo, tipo (entrada/ajuste), cantidad, costo, motivo; 2) si lo solicita OPERADOR → autorización; 3) ADMIN aprueba; 4) sistema registra movimiento y actualiza stock; 5) audita.
- **Excepciones:** rechazo de autorización.
- **Postcondiciones:** stock actualizado.

## CU-17 · Consultar reportes
- **Actor:** ADMIN (completo), OPERADOR (limitado).
- **Descripción:** ver y exportar información.
- **Precondiciones:** sesión válida.
- **Flujo principal:** 1) selecciona reporte y periodo; 2) sistema consulta filtrando por tenant; 3) muestra; 4) exporta a PDF/Excel.
- **Excepciones:** rango inválido; sin datos.
- **Postcondiciones:** reporte mostrado/exportado.

## CU-18 · Consultar auditoría
- **Actor:** ADMIN (su establecimiento), SUPER_ADMIN (global).
- **Descripción:** revisar la bitácora de acciones sensibles.
- **Precondiciones:** rol con permiso.
- **Flujo principal:** 1) filtra; 2) sistema muestra eventos; 3) abre detalle antes/después.
- **Postcondiciones:** consulta realizada (sin alterar datos).

## CU-19 · Administrar mesas / categorías / impresoras / proveedores (CRUD)
- **Actor:** ADMIN.
- **Descripción:** mantenimiento de catálogos del establecimiento.
- **Precondiciones:** sesión ADMIN.
- **Flujo principal:** crear/editar/activar/desactivar; sistema valida unicidad y dependencias; audita cambios sensibles.
- **Excepciones:** violación de unicidad; baja con dependencias activas.
- **Postcondiciones:** catálogo actualizado.

---

# FASE 6 — FLUJOS CRÍTICOS (paso a paso, con errores)

## F1 · Venta en mesa
1. Operador verifica caja abierta (si no → "Abre la caja").
2. Selecciona mesa libre → crea orden (folio nuevo, estado `abierta`); mesa pasa a ocupada (derivado).
3. Agrega productos; sistema recalcula totales.
4. Envía comanda a barra/cocina.
5. Cliente pide la cuenta → cobra (simple o dividido).
6. Saldo = 0 → orden `pagada`, ticket impreso, mesa liberada.
- **Errores:** mesa ya ocupada → bloqueo; sin caja → bloqueo; stock insuficiente → según política.

## F2 · Venta en barra
1. Verifica caja abierta.
2. Crea orden tipo barra (sin mesa).
3. Agrega productos.
4. Cobra de inmediato (flujo rápido) → orden `pagada`, ticket impreso.
- **Errores:** sin caja → bloqueo.

## F3 · Pago dividido
1. Orden con total T y saldo = T.
2. Registra pago 1 (tipo A, monto m1) → saldo = T − m1.
3. Registra pago 2 (tipo B, monto m2)… hasta saldo = 0.
4. Cierra orden, imprime ticket.
- **Errores:** intento de cerrar con saldo > 0 → "El pago no cubre el total"; sobrepago en tarjeta → no permitido.

## F4 · Cancelación de producto
1. Operador solicita cancelar ítem con motivo → autorización `pendiente`.
2. Admin revisa en bandeja → aprueba.
3. Sistema marca ítem `cancelado` (`cancelado_at`), revierte inventario si aplica, recalcula totales, enlaza autorización, audita.
- **Errores:** admin rechaza → ítem permanece; operador intenta cancelar sin autorización → bloqueo.

## F5 · Anulación de orden (walkout / error)
1. Operador solicita anular con motivo → autorización `pendiente`.
2. Admin aprueba.
3. Sistema marca orden `anulada`, revierte inventario si aplica, libera mesa, audita (pérdida registrada).
- **Errores:** rechazo → orden sigue abierta; intento de anular orden pagada → bloqueo.

## F6 · Ajuste de inventario
1. Solicita ajuste (entrada/corrección) con cantidad, costo y motivo.
2. Si es operador → autorización; admin aprueba.
3. Sistema registra movimiento, actualiza `stock_actual`, audita.
- **Errores:** rechazo → sin cambio; operador intenta incrementar stock directo → bloqueo.

## F7 · Merma
1. Selecciona insumo, tipo (merma/rotura/consumo), cantidad, motivo.
2. Sistema registra salida, actualiza stock, audita.
- **Errores:** cantidad > stock → según política (bloqueo o negativo con alerta, Fase 9).

## F8 · Apertura de caja
1. Verifica que no haya caja abierta.
2. Captura monto inicial → crea sesión `abierta`, audita.
- **Errores:** ya hay caja abierta → bloqueo.

## F9 · Cierre de caja
1. Verifica que no haya órdenes abiertas (si hay → bloqueo).
2. Sistema calcula monto del sistema (inicial + ventas en efectivo − salidas).
3. Captura monto contado → calcula diferencia → cierra sesión, audita.
- **Errores:** órdenes abiertas → bloqueo; diferencia ≠ 0 → registrada (Fase 9: ¿requiere motivo/autorización?).

## F10 · Reimpresión de ticket
1. Localiza orden pagada.
2. Solicita reimpresión → sistema regenera contenido y reimprime, registra ticket.
- **Errores:** orden no pagada → bloqueo; (Fase 9: ¿reimpresión requiere permiso/autorización y se audita?).

---

# FASE 7 — MATRIZ DE PERMISOS

> **Actualizada 2026-08-14 · 5 roles + roles a medida.** Esta fase documentaba 3 roles
> (super_admin/admin/operador) hasta hoy; el sistema tiene **5 roles preset** desde el
> 2026-08-05 y un **editor de roles a medida** desde el 2026-08-06.
>
> **Contrato completo (fuente de verdad):** `bar-pos-web/docs/MatrizRoles.md`, permiso por
> permiso. **Fuente única en código:** `app/Domain/Usuarios/CatalogoRoles.php` — agregar un
> rol es tocar solo ese archivo; `roles:sincronizar` (que corre en cada arranque del
> contenedor) lo propaga a los tenants nuevos y a los existentes.

Leyenda: ✅ Permitido · ❌ No permitido · 🔐 Requiere autorización (se solicita; admin/gerente aprueba).

**Los 5 roles.** `super_admin` administra la plataforma y no vende. `admin` es el dueño: autoridad máxima del tenant, conserva el POS (el bar de una persona también despacha), pero su *landing* es el dashboard. `gerente` es casi-admin operativo, sin decisiones estratégicas. `operador` es el cajero/despachador. `mesero` toma órdenes y cierra sus cuentas.

| Acción | SUPER_ADMIN | ADMIN | GERENTE | OPERADOR | MESERO |
|---|:--:|:--:|:--:|:--:|:--:|
| Crear/editar establecimiento | ✅ | ❌ | ❌ | ❌ | ❌ |
| Activar/desactivar establecimiento | ✅ | ❌ | ❌ | ❌ | ❌ |
| Asignar administrador · restablecer acceso | ✅ | ❌ | ❌ | ❌ | ❌ |
| Ver métricas globales · auditoría global | ✅ | ❌ | ❌ | ❌ | ❌ |
| Editar configuración del establecimiento | ❌ | ✅ | ❌ | ❌ | ❌ |
| Ver auditoría del establecimiento | ❌ | ✅ | ❌ | ❌ | ❌ |
| Administrar usuarios | ❌ | ✅ | ✅ ¹ | ❌ | ❌ |
| Administrar **admins** (`usuarios.gestionar_admins`) | ❌ | ✅ | ❌ | ❌ | ❌ |
| **Editor de roles a medida** (`roles.gestionar`) | ❌ | ✅ | ❌ | ❌ | ❌ |
| Administrar categorías · productos · recetas | ❌ | ✅ | ✅ | ❌ | ❌ |
| Administrar insumos · proveedores · unidades | ❌ | ✅ | ✅ | ❌ | ❌ |
| Administrar mesas · impresoras | ❌ | ✅ | ✅ | ❌ | ❌ |
| **Ver** catálogo y mesas para el POS (`*.ver`, `mesas.ver`) | ❌ | ✅ | ✅ | ✅ | ✅ |
| **Ver** estado de caja (`caja.ver`) | ❌ | ✅ | ✅ | ✅ | ✅ |
| Abrir caja · cerrar caja | ❌ | ✅ | ✅ | ✅ | ❌ |
| Crear orden · agregar productos · cobrar | ❌ | ✅ | ✅ | ✅ | ✅ |
| Aplicar descuento | ❌ | ✅ | ✅ | ✅ | ❌ |
| Imprimir ticket | ❌ | ✅ | ✅ | ✅ | ✅ |
| Reimprimir ticket ³ | ❌ | ✅ | ✅ | ✅ | ✅ |
| **Reasignar mesero de una orden** (`ordenes.reasignar`) | ❌ | ✅ | ✅ | ❌ | ❌ |
| Registrar merma/rotura/consumo | ❌ | ✅ | ✅ | ✅ | ❌ |
| Incrementar stock (entrada) · ajustar inventario | ❌ | ✅ | ✅ | 🔐 | ❌ |
| Cancelar producto de una orden · anular orden | ❌ | ✅ | ✅ | 🔐 | 🔐 |
| Solicitar autorización | ❌ | ✅ ² | ❌ | ✅ | ✅ |
| Autorizar operaciones sensibles | ❌ | ✅ | ✅ | ❌ | ❌ |
| Ver reportes del establecimiento | ❌ | ✅ | ✅ | Parcial (turno) | Parcial (turno) |
| **Ver dashboard de decisiones** (`reportes.ver_dashboard`, landing de `/app`) | ❌ | ✅ | ❌ | ❌ | ❌ |

**¹** El gerente gestiona usuarios pero **no admins**: no puede crear, editar ni asignar el rol `admin`. Se enforza en dos capas — los Form Requests (422 si el rol destino es admin) y `UsuarioPolicy::update/cambiarEstado/asignarRol` (403 si el objetivo ya es admin) — guiadas por el permiso `usuarios.gestionar_admins`.

**²** El admin tiene **todos** los permisos otorgables al tenant (invariante desde el editor de roles): sin `autorizaciones.solicitar` y `reportes.ver_limitado` no podía clonar el preset `mesero`, porque el editor aplica contención de privilegios ("no otorgas lo que tú no tienes"). No cambia lo que ve ni lo que hace.

**³ Reimpresión: directa para todos, pero SIEMPRE auditada** — decisión **P14** (§ Puntos a decidir, respondida por el dueño del producto: *"no se necesita autorización para reimpresión"*). `ReimprimirTicketService` registra `ticket.reimpreso` con orden, tipo y folio **dentro de la misma transacción** que encola la impresión: no se puede reimprimir sin dejar rastro. El control es la bitácora, no un bloqueo — exigir autorización dejaría al personal esperando al admin cada vez que la impresora corta mal un ticket, por un riesgo que no mueve dinero ni inventario. *Esta tabla marcaba 🔐 para el operador hasta el 2026-08-15, residuo del borrador anterior a P14.* P14 hablaba de "admin y operador" porque `mesero` no existía entonces; el mesero reimprime bajo el mismo criterio.

> **Roles a medida.** Además de los 5 presets, cada establecimiento puede **clonar** un preset y ajustarle los permisos (`roles.gestionar`, solo admin). Los presets son **inmutables** — `roles:sincronizar` pisaría cualquier edición. Un rol a medida no se puede eliminar si tiene usuarios asignados (409).

> **Nota:** el SUPER_ADMIN administra la plataforma, no opera la venta de un establecimiento; el acceso de soporte a un establecimiento (impersonación, `soporte.impersonacion`) sigue pendiente y sin consumidor.

---

# FASE 8 — REGLAS FUNCIONALES GLOBALES

**Aislamiento y acceso**
1. Toda la información se filtra por `id_establecimiento`; ningún establecimiento ve datos de otro.
2. El SUPER_ADMIN no pertenece a un establecimiento y no opera ventas.
3. Un usuario inactivo o de un establecimiento desactivado no puede iniciar sesión.

**Caja**
4. Solo puede existir una caja abierta por establecimiento.
5. No se puede vender sin caja abierta.
6. No se puede cerrar la caja con órdenes abiertas.
7. Una caja cerrada no se reabre.
8. La caja es del establecimiento (compartida entre operadores del turno).

**Mesas y órdenes**
9. Una mesa solo puede tener una orden abierta a la vez.
10. Al pagar una orden, la mesa se libera automáticamente.
11. El estado de la mesa se deriva de la orden abierta (no es fuente de verdad independiente).
12. El folio de orden es único por establecimiento.
13. Una orden pagada no se reabre; un nuevo consumo genera una nueva orden.
14. Una orden puede ser de mesa o de barra; barra no ocupa mesa.
15. Una orden con consumo no se "cancela": se anula (estado `anulada`) y queda registrada como pérdida.

**Productos e inventario**
16. La fuente de verdad del stock es el ledger de movimientos; `stock_actual` es cache.
17. Las ventas descuentan inventario automáticamente vía receta.
18. Un producto con `controla_inventario = false` no afecta inventario.
19. Toda merma, rotura, ajuste y consumo interno se registra y audita.
20. Un movimiento de inventario no se edita ni borra; se corrige con un movimiento inverso.

**Autorizaciones**
21. Toda cancelación de ítem, anulación de orden, incremento o ajuste de stock requiere autorización si la solicita un OPERADOR.
22. Una operación sensible no se ejecuta sin autorización aprobada.
23. El OPERADOR no autoriza; el ADMIN aprueba o rechaza.
24. Una solicitud resuelta (aprobada/rechazada) no cambia de estado.

**Pagos**
25. La orden se cierra solo cuando el saldo llega a cero.
26. Una orden admite múltiples pagos (pago dividido).
27. Al cerrar el cobro: se imprime ticket, se libera mesa y se cierra la orden.

**Auditoría**
28. La auditoría es append-only y visible para administradores.
29. Toda acción sensible registra usuario, acción, entidad y momento.

**Datos**
30. Las entidades maestras no se eliminan físicamente: se desactivan (soft delete).
31. Catálogos globales (tipos de orden, tipos de pago, unidades de medida) son compartidos por todos los establecimientos.

---

# FASE 9 — PENDIENTES FUNCIONALES (preguntas, no inventadas)

Decisiones que el negocio debe confirmar antes del diseño técnico:

**Inventario**
- P1. ¿En qué momento se descuenta el inventario: al agregar el ítem a la orden, al enviar la comanda, o al cobrar? Descontar al cobrar la orden.
- P2. Si el stock de un insumo es insuficiente, ¿se bloquea la venta o se permite stock negativo con alerta? Permitir venta + Mostrar alerta
- P3. Al cancelar un ítem o anular una orden que ya descontó inventario, ¿se revierte el stock automáticamente? Si una cancelación genera descuento automático revertir automáticamente, Si es ajuste manual: No revertir
- P4. ¿Las unidades de medida son un catálogo global de solo lectura o cada establecimiento puede crear las suyas? Cada establecimiento puede crear las suyas, pero si meter las que tiene predefinidas y puede agregar mas

**Caja**
- P5. Al cerrar con diferencia (faltante/sobrante), ¿basta con registrarla o requiere motivo/autorización del ADMIN? Registrar diferencia
+ Motivo obligatorio Sin autorización. Porque las diferencias suelen detectarse al cierre.
- P6. ¿El monto del sistema considera solo efectivo, o todos los tipos de pago? ¿Cómo se tratan tarjeta/transferencia en el arqueo? Efectivo = se arquea, Tarjeta = solo reporte, Transferencia = solo reporte
- P7. ¿Se permite registrar entradas/salidas de efectivo de caja (retiros, fondos) además de ventas? No

**Órdenes y pagos**
- P8. Pago dividido: ¿se divide por monto, en partes iguales, o también por ítems? por monto
- P9. Propina: ¿se captura por pago, se imprime en ticket, afecta el arqueo, se reporta por operador? Eso no se va a registrar, eso es para el mesero o la persona a la que se le dio la propina
- P10. Descuentos: ¿quién puede aplicar descuento a nivel ítem/orden, con qué tope y si requiere autorización? El cajero
- P11. Impuesto: ¿el `precio_venta` incluye impuesto o se suma aparte? ¿La tasa es configurable por establecimiento? Si, es configurable, y se suma a parte si es el caso
- P12. Folio: ¿formato esperado y reinicia por día o es secuencia continua por establecimiento? secuencia seguida por establecimiento
- P13. ¿Existen devoluciones/reembolsos en V1 o quedan para V2? no hay reembolsos 

**Impresión**
- P14. Reimpresión de ticket: ¿requiere permiso especial o autorización? ¿se audita cada reimpresión? no se necesita autorizacion para reimpresion
- P15. Si no hay impresora física configurada, ¿el ticket/comanda se muestra en pantalla o se exporta a PDF? se exporta a pdf

**Seguridad / plataforma**
- P16. Política de contraseñas (longitud/robustez) y de intentos fallidos (bloqueo temporal). **RESUELTO:** longitud mínima de 8 caracteres, **sin** bloqueo por intentos fallidos. Implementado como configurable.
- P17. ¿El SUPER_ADMIN puede acceder a un establecimiento para soporte (impersonación) y cómo se audita? si
- P18. ¿Caduca la sesión por inactividad? ¿Tiempo? no
- P19. Al desactivar un establecimiento con caja u órdenes abiertas, ¿qué ocurre (bloqueo, cierre forzado)? bloquear las ordenes 

**Reportes**
- P20. Reporte de utilidad/margen: ¿el costo proviene de `costo_referencia`, del costo de insumos (promedio), o de ambos según el producto? **RESUELTO:** según el producto — `controla_inventario = false` usa `costo_referencia`; `controla_inventario = true` usa el costo de los insumos de su receta. Implementado como configurable.
- P21. Alcance exacto de los reportes que ve el OPERADOR (¿solo su turno/caja?). sip

---

# FASE 10 — ENTREGABLE FINAL

Este documento **es** la **ESPECIFICACIÓN FUNCIONAL MVP V1** e integra:

- **Módulos** (Fase 1) — 15 módulos funcionales con objetivo, alcance y dependencias.
- **Funcionalidades por módulo** (Fase 2) — con reglas, restricciones y casos especiales.
- **Pantallas** (Fase 3) — objetivo, acciones y componentes por módulo.
- **Validaciones** (Fase 4) — reglas, mensajes de error y casos límite por pantalla.
- **Casos de uso** (Fase 5) — 19 casos con actor, flujos, excepciones y postcondiciones.
- **Flujos críticos** (Fase 6) — 10 flujos paso a paso con escenarios de error.
- **Matriz de permisos** (Fase 7) — acciones × roles (permitido / no permitido / requiere autorización).
- **Reglas funcionales globales** (Fase 8) — 31 reglas consolidadas.
- **Pendientes funcionales** (Fase 9) — 21 preguntas abiertas para el negocio.

**Estado:** listo para pasar a (1) Arquitectura Laravel, (2) Arquitectura React, (3) Diseño de API REST, (4) Plan de desarrollo y (5) Inicio de programación.

**Condición previa recomendada:** resolver los pendientes de la Fase 9 (especialmente P1–P3 de inventario y P5–P6 de caja) antes de congelar el diseño de API, ya que impactan el contrato de varios endpoints y la lógica transaccional.
