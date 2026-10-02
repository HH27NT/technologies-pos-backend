# DER — Documentación Oficial del Modelo de Datos

**Proyecto:** SaaS POS Multi-Tenant para bares, cervecerías, cantinas, cafeterías y restaurantes pequeños
**Versión del modelo:** DER V1.4 (25 tablas de dominio + tablas de infraestructura de Spatie/Sanctum)
**Motor objetivo:** PostgreSQL 15+
**Propósito del documento:** fuente de verdad del esquema para desarrollo, mantenimiento y generación de código. Documenta únicamente lo existente en el DER.

**Cambios de V1.4 (2026-08-15) — modo terminal compartida.** El SaaS soporta **dos formas de operar** y el esquema las absorbe con un campo nullable, sin duplicar lógica: (1) `ordenes.id_mesero` y `pagos.id_mesero`, **nullable**, guardan a la *persona* cuando varios meseros comparten una tablet; (2) `configuracion_establecimiento` gana `terminal_compartida` y `bloqueo_terminal_segundos`; (3) tabla nueva **`mesero_pins`** (§25).

> **Regla de resolución — leer antes de escribir cualquier consulta sobre "de quién es esta venta".**
>
> ```
> mesero_efectivo = COALESCE(id_mesero, id_usuario)
> ```
>
> Con **dispositivo por mesero**, `id_usuario` ya es la persona y `id_mesero` viaja nulo. Con **terminal compartida**, `id_usuario` es la cuenta del dispositivo y la persona está en `id_mesero`. Implementada en `App\Domain\Ordenes\MeseroEfectivo`; **usarla siempre**. Leer `id_usuario` a pelo da un resultado equivocado *sin fallar* en el modo compartido, que es la peor forma de estar equivocado: el reporte sale, nadie sospecha y el número es falso.

**Cambios respecto a V1.2 (sincronizado con las migraciones al 2026-08-14).** Ocho migraciones posteriores al cierre de V1.2 no estaban reflejadas: (1) `sesiones_caja` gana `motivo`; (2) índice único parcial `uq_pagos_orden_referencia_parcial` (idempotencia de pagos, solo PostgreSQL); (3) `autorizaciones` gana `datos` (JSONB) y (4) `metodo` (`asincrono`/`override`); (5) tabla nueva **`autorizacion_pins`** y (6) tabla nueva **`autorizacion_intentos`** (M14.1, override por PIN); (7) permisos nuevos de catálogo (`mesas.ver`, `caja.ver` — dato, no esquema); (8) `roles` gana `etiqueta` y `descripcion` (editor de roles a medida).

> **⚠️ Corrección importante de V1.2.** La sección `roles` describía un catálogo **global** con campos `nombre`/`slug`. Eso nunca fue el esquema implementado: `roles` es la tabla de **Spatie Permission con _teams_** (`team_foreign_key = id_establecimiento`), por lo que los roles son **por establecimiento**, no globales. Ver §3.

**Cambios respecto a V1.1 (ratificados de la Fase 9).** (1) `unidades_medida` deja de ser catálogo global puro y pasa a **híbrido**: gana `id_establecimiento` nullable (NULL = unidad predefinida global; no nula = unidad propia del establecimiento). (2) `configuracion_establecimiento` gana `aplica_impuesto` y `tasa_impuesto`. (3) `pagos.propina` se conserva, documentada como **reservada para V2** (sin uso en V1). (4) `detalle_orden` gana `enviado` (BOOLEAN, default `false`) para distinguir los ítems ya enviados a comanda de los que aún admiten modificación de cantidad (ratificado al cerrar la documentación).

**Convenciones de lectura.** En el diagrama, varios campos aparecen agrupados en una sola línea por economía visual (p. ej. `descuento / subtotal / impuesto / total` o `created_at / updated_at / deleted_at`). En esta documentación se tratan como **columnas independientes**. Toda tabla operativa incluye la columna discriminadora de tenant `id_establecimiento`; las tablas marcadas **(GLOBAL)** no la incluyen y son compartidas por todos los establecimientos.

---

## 1. establecimientos

**Descripción funcional.** Negocio cliente del SaaS (bar, cantina, cafetería, etc.). Es la raíz del modelo multi-tenant.
**Propósito.** Unidad de aislamiento de datos: casi todas las demás tablas dependen de esta vía `id_establecimiento`.

- **PK:** `id` (BIGINT)
- **FK:** ninguna.
- **Campos:** `id`, `nombre`, `razon_social`, `rfc`, `direccion`, `telefono`, `email`, `logo_url`, `zona_horaria`, `moneda`, `activo`, `created_at`, `updated_at`, `deleted_at`.
- **Índices/Restricciones:** PK en `id`; soft delete por `deleted_at`.
- **Cardinalidades:** 1 establecimiento → N usuarios, mesas, órdenes, productos, insumos, etc.; 1 establecimiento → 1 configuración.
- **Relaciones:** padre de prácticamente todas las tablas operativas; `hasOne` con `configuracion_establecimiento`.

---

## 2. configuracion_establecimiento

**Descripción funcional.** Parámetros operativos y de impresión de un establecimiento.
**Propósito.** Separar la configuración mutable (datos de ticket, impresión automática, stock mínimo global) de los datos base del establecimiento.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id` (UNIQUE, relación 1:1).
- **Campos:** `id`, `id_establecimiento`, `nombre_comercial`, `telefono_ticket`, `direccion_ticket`, `impresion_automatica`, `terminal_compartida`, `bloqueo_terminal_segundos`, `stock_minimo_global`, `aplica_impuesto`, `tasa_impuesto`, `created_at`, `updated_at`.
  - `terminal_compartida` (BOOL, default `false`) — varios meseros comparten una tablet. Apagado, el POS no pide PIN y `id_mesero` nunca se llena: el establecimiento con dispositivo por mesero no configura nada.
  - `bloqueo_terminal_segundos` (SMALLINT, default 120) — auto-bloqueo por inactividad de la tablet. Una terminal desatendida en la barra es el riesgo que introduce este modo.
- **Índices/Restricciones:** PK en `id`; UNIQUE en `id_establecimiento` (garantiza 1:1).
- **Cardinalidades:** 1 establecimiento → 1 configuración.
- **Relaciones:** `belongsTo establecimientos`.

---

## 3. roles

**Descripción funcional.** Roles **por establecimiento**: paquetes de permisos que definen qué puede hacer cada persona. Es la tabla de **Spatie Permission con _teams_**, no un catálogo global.
**Propósito.** Sostener el RBAC. La UI se pinta **por permiso**, nunca por nombre de rol.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` (el `team_foreign_key` de Spatie, **nullable**: NULL = rol de plataforma, p. ej. `super_admin`).
- **Campos:** `id`, `id_establecimiento`, `name`, `guard_name`, `etiqueta`, `descripcion`, `created_at`, `updated_at`.
  - `name` — identificador **técnico** de Spatie (`admin`, `gerente`, `mesero`…). Estable, sin espacios; es lo que consultan `hasRole()` y `model_has_roles`. **Nunca se muestra en la UI.**
  - `etiqueta` (VARCHAR 60, NULL) — nombre legible y **renombrable** de un rol a medida. Los roles del sistema la dejan nula y se etiquetan desde `CatalogoRoles`.
  - `descripcion` (VARCHAR 200, NULL) — para qué sirve el rol, mostrado en el editor.
- **Índices/Restricciones:** PK en `id`; UNIQUE(`id_establecimiento`, `name`, `guard_name`); índice en `id_establecimiento`.
- **Cardinalidades:** 1 rol → N usuarios (vía `model_has_roles`, y cacheado en `usuarios.id_rol`); N roles ↔ N permisos (vía `role_has_permissions`).
- **Relaciones:** `belongsTo establecimientos` (nullable); referenciada por `usuarios.id_rol` (cache) y por las pivote de Spatie.

> **Presets vs. roles a medida.** Los roles del sistema (`super_admin`, `admin`, `gerente`, `operador`, `mesero`) se materializan **por tenant** desde `App\Domain\Usuarios\CatalogoRoles` (fuente única) y el comando `roles:sincronizar` los propaga en cada arranque. Son **inmutables**: el editor los **clona**, no los edita, porque `roles:sincronizar` pisaría cualquier cambio y unos presets intactos mantienen el soporte diagnosticable. Los roles a medida (`es_sistema` falso, es decir: los que no están en el catálogo) sí se editan y borran, y el borrado responde 409 si tienen usuarios asignados.

---

## 3-bis. Tablas de permisos (Spatie) — infraestructura

No estaban documentadas en V1.2 y son parte del esquema real:

- **`permissions`** — catálogo de permisos. Campos: `id`, `name` (p. ej. `ordenes.cobrar`), `guard_name`, timestamps. UNIQUE(`name`, `guard_name`). Es **global**, no por tenant.
- **`role_has_permissions`** — pivote rol ↔ permiso. PK compuesta (`permission_id`, `role_id`).
- **`model_has_roles`** — asignación usuario ↔ rol, **con `id_establecimiento`** en la PK compuesta (por _teams_): la misma persona puede tener rol distinto en establecimientos distintos.
- **`model_has_permissions`** — permisos directos a un usuario (sin rol intermedio). Misma forma, con tenant en la PK. No se usa en V1: todo permiso llega por rol.
- **`personal_access_tokens`** — tokens de Sanctum (autenticación por Bearer).

---

## 4. usuarios

**Descripción funcional.** Personas que acceden al sistema: super administradores de plataforma y personal de cada establecimiento.
**Propósito.** Autenticación, autorización por rol y trazabilidad de acciones.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id` (**NULL = super_admin**); `id_rol` → `roles.id` (**nullable**).
- **Campos:** `id`, `id_establecimiento`, `id_rol`, `nombre`, `email`, `username`, `password_hash`, `activo`, `remember_token`, `email_verified_at`, `created_at`, `updated_at`, `deleted_at`.
- **Índices/Restricciones:** PK en `id`; UNIQUE(`id_establecimiento`, `email`); UNIQUE(`id_establecimiento`, `username`); soft delete por `deleted_at`.
- **⚠️ `id_rol` es un _cache_, no la verdad.** La asignación real de roles vive en `model_has_roles` (Spatie con teams). `id_rol` duplica el rol principal para consultas simples y debe mantenerse coherente con la pivote: es un **invariante** del sistema (0 divergencias verificadas en el diagnóstico del 2026-08-05). Cualquier código que asigne rol debe escribir en **ambos** lados.
- **Identidad:** `email` y `username` son ambos nullable, pero al menos uno debe existir (`required_without` en los Form Requests). El login acepta cualquiera de los dos.
- **Cardinalidades:** 1 usuario → N órdenes, pagos, tickets, movimientos de inventario, sesiones de caja.
- **Relaciones:** `belongsTo establecimientos`, `belongsTo roles`; padre de órdenes, pagos, tickets, movimientos, sesiones de caja, y de las autorizaciones (como solicitante y como autorizador).

---

## 5. sesiones_caja

**Descripción funcional.** Periodo entre una apertura y un cierre de caja en un establecimiento.
**Propósito.** Controlar el ciclo de caja: habilitar la venta, registrar el arqueo y las diferencias.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`; `id_usuario_apertura` → `usuarios.id`; `id_usuario_cierre` → `usuarios.id` (NULL hasta el cierre).
- **Campos:** `id`, `id_establecimiento`, `id_usuario_apertura`, `id_usuario_cierre`, `monto_inicial`, `monto_sistema`, `monto_contado`, `diferencia`, `motivo`, `estado`, `abierta_at`, `cerrada_at`.
  - `motivo` (VARCHAR 500, NULL) — justificación que el cajero escribe al cerrar con diferencia. Columna portable (sin índices) para conservar la paridad SQLite ↔ PostgreSQL.
- **Índices/Restricciones:** PK en `id`; índice único parcial: **una sola sesión `abierta` por establecimiento**; `estado` ENUM(`abierta`, `cerrada`).
- **Cardinalidades:** 1 sesión de caja → N órdenes.
- **Relaciones:** `belongsTo establecimientos`; `belongsTo usuarios` (apertura y cierre); `hasMany ordenes`.

---

## 6. mesas

**Descripción funcional.** Posiciones de consumo de un establecimiento.
**Propósito.** Asociar órdenes de tipo mesa y reflejar disponibilidad.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`.
- **Campos:** `id`, `id_establecimiento`, `numero`, `nombre`, `zona`, `capacidad`, `activa`, `deleted_at`.
- **Índices/Restricciones:** PK en `id`; UNIQUE(`id_establecimiento`, `numero`); soft delete por `deleted_at`.
- **Cardinalidades:** 1 mesa → 0..N órdenes (a lo largo del tiempo; solo una abierta a la vez, garantizado en `ordenes`).
- **Relaciones:** `belongsTo establecimientos`; `hasMany ordenes`.

---

## 7. tipos_orden (GLOBAL)

**Descripción funcional.** Catálogo global del tipo de una orden.
**Propósito.** Clasificar la orden como mesa, barra o para llevar.

- **PK:** `id` (BIGINT)
- **FK:** ninguna (catálogo global, sin tenant).
- **Campos:** `id`, `nombre`, `activo`.
- **Índices/Restricciones:** PK en `id`. Datos semilla: `mesa`, `barra`, `llevar`.
- **Cardinalidades:** 1 tipo de orden → N órdenes.
- **Relaciones:** referenciada por `ordenes.id_tipo_orden`.

---

## 8. ordenes

**Descripción funcional.** Cuenta de consumo de un cliente, de tipo mesa o barra.
**Propósito.** Núcleo transaccional de la venta: agrupa ítems, pagos y tickets.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`; `id_sesion_caja` → `sesiones_caja.id`; `id_mesa` → `mesas.id` (NULL para barra/llevar); `id_tipo_orden` → `tipos_orden.id`; `id_usuario` → `usuarios.id`.
- **Campos:** `id`, `id_establecimiento`, `id_sesion_caja`, `id_mesa`, `id_tipo_orden`, `id_usuario`, `id_mesero`, `folio`, `estado`, `descuento`, `subtotal`, `impuesto`, `total`, `notas`, `abierta_at`, `cerrada_at`.
  - `id_usuario` — la **cuenta** que abrió la orden.
  - `id_mesero` (NULL) — la **persona**, cuando la cuenta no la identifica (terminal compartida). Se llena solo al firmar con PIN. De quién es la venta se responde con `COALESCE(id_mesero, id_usuario)`, nunca leyendo un campo suelto.
- **Índices/Restricciones:** PK en `id`; UNIQUE(`id_establecimiento`, `folio`); índice único parcial **una orden `abierta` por mesa**; `estado` ENUM(`abierta`, `pagada`, `anulada`); la transición a `anulada` requiere autorización (regla de negocio).
- **Cardinalidades:** 1 orden → N detalle_orden, N pagos, N tickets, 0..N movimientos_inventario.
- **Relaciones:** `belongsTo establecimientos, sesiones_caja, mesas, tipos_orden, usuarios`; `hasMany detalle_orden, pagos, tickets, movimientos_inventario`.

---

## 9. detalle_orden

**Descripción funcional.** Renglón de una orden: un producto con su cantidad y precio.
**Propósito.** Detallar el consumo y permitir cancelación por ítem.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`; `id_orden` → `ordenes.id` (**ON DELETE CASCADE**); `id_producto` → `productos.id`; `id_autorizacion` → `autorizaciones.id` (NULL).
- **Campos:** `id`, `id_establecimiento`, `id_orden`, `id_producto`, `id_autorizacion`, `cantidad`, `precio_unitario`, `descuento_item`, `subtotal`, `enviado`, `estado_item`, `cancelado_at`, `notas`.
- **Índices/Restricciones:** PK en `id`; `enviado` BOOLEAN (default `false`); `estado_item` ENUM(`activo`, `cancelado`); `cancelado_at` se llena al cancelar; `id_autorizacion` vincula la cancelación a su autorización. **Regla:** la cantidad de un ítem solo se modifica mientras `enviado = false`; pasa a `true` al confirmar la comanda y desde entonces el renglón solo se cancela (con autorización).
- **Cardinalidades:** N detalle_orden → 1 orden; N detalle_orden → 1 producto.
- **Relaciones:** `belongsTo ordenes, productos, autorizaciones`.

---

## 10. tipos_pago (GLOBAL)

**Descripción funcional.** Catálogo global de medios de pago.
**Propósito.** Clasificar cada pago (efectivo, tarjeta, transferencia).

- **PK:** `id` (BIGINT)
- **FK:** ninguna (catálogo global).
- **Campos:** `id`, `nombre`, `activo`.
- **Índices/Restricciones:** PK en `id`. Datos semilla: `efectivo`, `tarjeta`, `transferencia`.
- **Cardinalidades:** 1 tipo de pago → N pagos.
- **Relaciones:** referenciada por `pagos.id_tipo_pago`.

---

## 11. pagos

**Descripción funcional.** Registro de un cobro aplicado a una orden.
**Propósito.** Soportar el cobro, incluido el pago dividido (varios pagos por orden).

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`; `id_orden` → `ordenes.id`; `id_tipo_pago` → `tipos_pago.id`; `id_usuario` → `usuarios.id`.
- **Campos:** `id`, `id_establecimiento`, `id_orden`, `id_tipo_pago`, `id_usuario`, `id_mesero`, `monto`, `propina` (reservado V2, sin uso en V1), `referencia`, `pagado_at`.
  - `id_mesero` (NULL) — firma del cobro en terminal compartida. Va aparte del de la orden porque **quien abre la mesa no siempre es quien cobra**, y la pregunta del dueño ("cuánto entró por cada quien") se responde sobre los pagos.
- **Índices/Restricciones:** PK en `id`; **índice único parcial `uq_pagos_orden_referencia_parcial` sobre (`id_orden`, `referencia`) WHERE `referencia IS NOT NULL`** — idempotencia del cobro: evita duplicar un pago con la misma referencia (reintento de red, doble toque en "Cobrar"). Los pagos sin referencia (efectivo simple) no se restringen, porque dos billetes iguales sí son dos pagos. **Solo PostgreSQL**: en SQLite la idempotencia recae en la verificación de `RegistrarPagoService` bajo bloqueo.
- **Cardinalidades:** N pagos → 1 orden (pago dividido); N pagos → 1 tipo de pago.
- **Relaciones:** `belongsTo establecimientos, ordenes, tipos_pago, usuarios`.

---

## 12. tickets

**Descripción funcional.** Documento impreso (o exportado) asociado a una orden: comanda o ticket de cobro.
**Propósito.** Registrar y permitir reimpresión de comandas y tickets; almacenar su contenido.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`; `id_orden` → `ordenes.id`; `id_usuario` → `usuarios.id`; `id_impresora` → `impresoras.id` (NULL).
- **Campos:** `id`, `id_establecimiento`, `id_orden`, `id_usuario`, `id_impresora`, `folio_ticket`, `contenido_json`, `tipo`, `impreso_at`.
- **Índices/Restricciones:** PK en `id`; `tipo` ENUM(`comanda`, `cobro`); `contenido_json` de tipo JSONB.
- **Cardinalidades:** N tickets → 1 orden; 0..N tickets → 1 impresora.
- **Relaciones:** `belongsTo establecimientos, ordenes, usuarios, impresoras`.

---

## 13. impresoras

**Descripción funcional.** Impresora física configurada en un establecimiento.
**Propósito.** Enrutar comandas y tickets al dispositivo correspondiente (ticket, barra, cocina, administración).

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`.
- **Campos:** `id`, `id_establecimiento`, `nombre`, `tipo`, `conexion`, `activa`, `deleted_at`.
- **Índices/Restricciones:** PK en `id`; `tipo` ENUM(`ticket`, `barra`, `cocina`, `admin`); soft delete por `deleted_at`.
- **Cardinalidades:** 1 impresora → 0..N tickets.
- **Relaciones:** `belongsTo establecimientos`; `hasMany tickets`.

---

## 14. categorias_producto

**Descripción funcional.** Agrupación de productos para la venta.
**Propósito.** Organizar el catálogo y su orden de despliegue.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`.
- **Campos:** `id`, `id_establecimiento`, `nombre`, `orden_display`, `activo`, `deleted_at`.
- **Índices/Restricciones:** PK en `id`; soft delete por `deleted_at`.
- **Cardinalidades:** 1 categoría → N productos.
- **Relaciones:** `belongsTo establecimientos`; `hasMany productos`.

---

## 15. productos

**Descripción funcional.** Ítem vendible del catálogo (cervezas, preparados, botellas, snacks, comida).
**Propósito.** Definir precio de venta, costo de referencia y si descuenta inventario.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`; `id_categoria` → `categorias_producto.id`.
- **Campos:** `id`, `id_establecimiento`, `id_categoria`, `nombre`, `descripcion`, `precio_venta`, `costo_referencia`, `controla_inventario`, `disponible`, `sku`, `deleted_at`.
- **Índices/Restricciones:** PK en `id`; soft delete por `deleted_at`.
- **Cardinalidades:** 1 producto → N detalle_orden; 1 producto → N recetas_producto.
- **Relaciones:** `belongsTo establecimientos, categorias_producto`; `hasMany detalle_orden, recetas_producto`.

---

## 16. unidades_medida (GLOBAL + PROPIAS)

**Descripción funcional.** Catálogo híbrido de unidades de medida de insumos: unidades predefinidas compartidas más unidades propias de cada establecimiento.
**Propósito.** Estandarizar la unidad en que se controla cada insumo, permitiendo que cada establecimiento agregue las suyas sobre una base predefinida.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id` (**NULL = unidad predefinida global**; no nula = unidad propia del establecimiento).
- **Campos:** `id`, `id_establecimiento`, `nombre`, `abreviacion`.
- **Índices/Restricciones:** PK en `id`. Las filas con `id_establecimiento` NULL son predefinidas (semilla) y de solo lectura para los establecimientos.
- **Cardinalidades:** 1 unidad → N insumos; 1 establecimiento → 0..N unidades propias.
- **Relaciones:** referenciada por `insumos.id_unidad_medida`; `belongsTo establecimientos` (nullable, solo las propias).

---

## 17. proveedores

**Descripción funcional.** Proveedor de insumos de un establecimiento.
**Propósito.** Asociar insumos a su origen de compra.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`.
- **Campos:** `id`, `id_establecimiento`, `nombre`, `telefono`, `email`, `activo`, `deleted_at`.
- **Índices/Restricciones:** PK en `id`; soft delete por `deleted_at`.
- **Cardinalidades:** 1 proveedor → 0..N insumos.
- **Relaciones:** `belongsTo establecimientos`; `hasMany insumos`.

---

## 18. insumos

**Descripción funcional.** Artículo de inventario con control de existencias.
**Propósito.** Llevar el stock; es la entidad que se descuenta al vender (vía receta) y en movimientos manuales.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`; `id_unidad_medida` → `unidades_medida.id`; `id_proveedor` → `proveedores.id` (NULL).
- **Campos:** `id`, `id_establecimiento`, `id_unidad_medida`, `id_proveedor`, `nombre`, `stock_actual`, `stock_minimo`, `costo_unitario`, `activo`, `deleted_at`.
- **Índices/Restricciones:** PK en `id`; soft delete por `deleted_at`. `stock_actual` es un valor cache (la fuente de verdad es `movimientos_inventario`).
- **Cardinalidades:** 1 insumo → N recetas_producto; 1 insumo → N movimientos_inventario.
- **Relaciones:** `belongsTo establecimientos, unidades_medida, proveedores`; `hasMany recetas_producto, movimientos_inventario`.

---

## 19. recetas_producto

**Descripción funcional.** Línea de receta (BOM): insumo y cantidad que consume un producto al venderse.
**Propósito.** Permitir el descuento automático de inventario por insumo cuando se vende un producto.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`; `id_producto` → `productos.id`; `id_insumo` → `insumos.id`.
- **Campos:** `id`, `id_establecimiento`, `id_producto`, `id_insumo`, `cantidad`.
- **Índices/Restricciones:** PK en `id`; UNIQUE(`id_producto`, `id_insumo`) — un insumo no se repite en la misma receta.
- **Cardinalidades:** N recetas → 1 producto; N recetas → 1 insumo (tabla puente producto↔insumo con atributo `cantidad`).
- **Relaciones:** `belongsTo productos, insumos`.

---

## 20. movimientos_inventario

**Descripción funcional.** Ledger (libro mayor) append-only de todos los movimientos de stock de un insumo.
**Propósito.** Ser la fuente de verdad del inventario y su trazabilidad: ventas, entradas, ajustes y mermas.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`; `id_insumo` → `insumos.id`; `id_usuario` → `usuarios.id`; `id_orden` → `ordenes.id` (NULL; presente en salidas por venta); `id_autorizacion` → `autorizaciones.id` (NULL).
- **Campos:** `id`, `id_establecimiento`, `id_insumo`, `id_usuario`, `id_orden`, `id_autorizacion`, `tipo`, `cantidad`, `costo_unitario`, `stock_resultante`, `motivo`.
- **Índices/Restricciones:** PK en `id`; `tipo` ENUM(valores: `entrada`, `salida`, `venta`, `merma`, `rotura`, `ajuste`, `consumo_interno`); registro append-only (no se edita ni borra).
- **Cardinalidades:** N movimientos → 1 insumo; N movimientos → 1 usuario; 0..N movimientos → 1 orden; 0..N movimientos → 1 autorización.
- **Relaciones:** `belongsTo insumos, usuarios, ordenes, autorizaciones`.

---

## 21. autorizaciones

**Descripción funcional.** Solicitud de una operación sensible que un administrador debe aprobar.
**Propósito.** Materializar el flujo "operador solicita → admin autoriza → sistema ejecuta → sistema audita".

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id`; `id_usuario_solicita` → `usuarios.id`; `id_usuario_autoriza` → `usuarios.id` (NULL hasta resolverse).
- **Campos:** `id`, `id_establecimiento`, `id_usuario_solicita`, `id_usuario_autoriza`, `tipo`, `entidad`, `entidad_id`, `estado`, `metodo`, `motivo`, `datos`, `resuelta_at`.
  - `datos` (JSONB, NULL) — parámetros de la operación pendiente. Necesario porque una solicitud de entrada/ajuste de stock lleva `id_insumo`/`cantidad`/`costo_unitario` que **todavía no existen como movimiento**: el movimiento se crea al aprobar.
  - `metodo` (ENUM `asincrono` | `override`, default `asincrono`) — origen de la aprobación. `asincrono`: el operador solicita y el admin resuelve desde la bandeja. `override`: el autorizador teclea su **PIN** y la operación se ejecuta al instante (M14.1).
- **Índices/Restricciones:** PK en `id`; `tipo` ENUM(valores: `cancelar_item`, `anular_orden`, `ajuste_stock`, … ); `estado` ENUM(`pendiente`, `aprobada`, `rechazada`). `entidad` + `entidad_id` forman una referencia polimórfica a la entidad afectada (no es FK formal).
- **Cardinalidades:** 1 autorización → 0..N detalle_orden; 1 autorización → 0..N movimientos_inventario.
- **Relaciones:** `belongsTo establecimientos`; `belongsTo usuarios` (solicita y autoriza); referenciada por `detalle_orden.id_autorizacion` y `movimientos_inventario.id_autorizacion`.

---

## 22. auditoria

**Descripción funcional.** Bitácora append-only de acciones sensibles del sistema.
**Propósito.** Trazabilidad visible para administradores (caja, órdenes, cancelaciones, ajustes, usuarios, autorizaciones).

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id` (NULL para acciones del super_admin); `id_usuario` → `usuarios.id` (NULL).
- **Campos:** `id`, `id_establecimiento`, `id_usuario`, `accion`, `entidad`, `entidad_id`, `datos_antes`, `datos_despues`, `ip`, `created_at`.
- **Índices/Restricciones:** PK en `id`; `datos_antes` y `datos_despues` de tipo JSONB; registro append-only. `entidad` + `entidad_id` forman una referencia polimórfica (no es FK formal).
- **Cardinalidades:** N registros de auditoría → 1 establecimiento (o ninguno); N → 1 usuario (o ninguno).
- **Relaciones:** `belongsTo establecimientos` (nullable), `belongsTo usuarios` (nullable).

---

## 23. autorizacion_pins

**Descripción funcional.** PIN de 6 dígitos que cada autorizador fija para sí mismo (self-service).
**Propósito.** Permitir el **override** de M14.1: el operador teclea el PIN de un autorizador presente y la operación sensible se ejecuta al instante, sin esperar la bandeja asíncrona.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id` (cascade); `id_usuario` → `usuarios.id` (cascade).
- **Campos:** `id`, `id_establecimiento`, `id_usuario`, `pin_hash`, `pin_lookup`, `actualizado_at`, `created_at`, `updated_at`.
  - `pin_hash` — bcrypt con salt por fila. Es la verificación **real** (timing-safe, `Hash::check`).
  - `pin_lookup` (VARCHAR 64) — HMAC-SHA256 de `"{id_establecimiento}:{pin}"` con **`POS_PIN_LOOKUP_KEY`** (clave dedicada, **no** la `APP_KEY`). Determinístico, permite resolver la identidad con un `WHERE` indexado y enforzar unicidad sin exponer el PIN.
- **Índices/Restricciones:** UNIQUE(`id_usuario`, `id_establecimiento`) — un PIN por persona y tenant; UNIQUE(`id_establecimiento`, `pin_lookup`) — el PIN debe ser único dentro del establecimiento, condición para poder resolver la identidad tecleando solo 6 dígitos.
- **⚠️ Operación:** si `POS_PIN_LOOKUP_KEY` rota, **todos los lookup quedan inservibles** y cada autorizador debe volver a fijar su PIN. Se genera con `php artisan pos:pin-key`. La `APP_KEY` sí puede rotarse sin invalidar PINs, precisamente por usar clave dedicada.

---

## 24. autorizacion_intentos

**Descripción funcional.** Bitácora de intentos de override **fallidos**.
**Propósito.** `autorizaciones` solo guarda operaciones concedidas; los rechazos necesitan su propio rastro para detectar tanteo de PINs.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id` (cascade); `id_usuario_solicita` → `usuarios.id` (el operador que tecleó).
- **Campos:** `id`, `id_establecimiento`, `id_usuario_solicita`, `tipo`, `resultado`, `ip`, `terminal`, `datos`, `created_at`, `updated_at`.
  - `resultado` — `pin_invalido` (no resuelve o no verifica) | `sin_permiso` (resuelve a alguien que no puede autorizar esa operación).
- **Índices/Restricciones:** índice (`id_establecimiento`, `created_at`).
- **🔒 Seguridad:** **nunca** se guarda el PIN tecleado, ni en claro ni hasheado.

---

## 25. mesero_pins

**Descripción funcional.** PIN de 6 dígitos con el que un mesero se identifica en una terminal compartida.
**Propósito.** Atribuir la venta a la **persona** cuando la cuenta del dispositivo no la identifica.

- **PK:** `id` (BIGINT)
- **FK:** `id_establecimiento` → `establecimientos.id` (cascade); `id_usuario` → `usuarios.id` (cascade).
- **Campos:** `id`, `id_establecimiento`, `id_usuario`, `pin_hash`, `pin_lookup`, `actualizado_at`, `created_at`, `updated_at`.
- **Índices/Restricciones:** UNIQUE(`id_usuario`, `id_establecimiento`); UNIQUE(`id_establecimiento`, `pin_lookup`) — el PIN debe ser único en el local, o sería imposible saber a quién atribuir la venta.

> **⚠️ Por qué NO reutiliza `autorizacion_pins`.** El PIN de autorización aprueba dinero (anula
> órdenes, ajusta inventario); este se teclea decenas de veces por turno en una tablet de la
> barra, delante de compañeros y clientes. Compartir el secreto significaría que un gerente que
> atiende mesas expone en público la llave de las anulaciones. Son tablas y secretos distintos,
> el HMAC lleva prefijo propio (`mesero:`) para que ambas columnas sean incomparables, y el flujo
> de override **rechaza** un PIN de mesero (blindado por test).
>
> **Este PIN jamás autoriza ni autentica:** no emite token ni concede permisos. Identificar
> devuelve un **token opaco de vida corta** (mismo plazo que el auto-bloqueo) que el POS adjunta
> al crear la orden y al cobrar. Sin él, `id_mesero` viajaría desde el cliente y **la atribución
> sería falsificable**, que es justo lo que este diseño evita.

---

# Resumen General de Relaciones

**Eje multi-tenant.** `establecimientos` es la raíz. Tiene relación 1:1 con `configuracion_establecimiento` y 1:N con todas las tablas operativas mediante `id_establecimiento`: `usuarios`, `mesas`, `ordenes`, `sesiones_caja`, `categorias_producto`, `productos`, `proveedores`, `insumos`, `recetas_producto`, `movimientos_inventario`, `pagos`, `tickets`, `impresoras`, `autorizaciones` y `auditoria`.

**Catálogos globales (sin tenant).** `tipos_orden` y `tipos_pago` son compartidos por todos los establecimientos, referenciados por `ordenes.id_tipo_orden` y `pagos.id_tipo_pago`. **`permissions` también es global** (el catálogo de permisos es el mismo para todos). **`roles` NO es global:** lleva `id_establecimiento` (team de Spatie) y se materializa por tenant desde `CatalogoRoles`; `usuarios.id_rol` apunta a la fila del rol **de ese establecimiento**. **`unidades_medida` es híbrido:** combina filas predefinidas globales (`id_establecimiento` NULL) con unidades propias de cada establecimiento; es referenciado por `insumos.id_unidad_medida`.

**Seguridad y personal.** `roles` 1:N `usuarios` **dentro de cada establecimiento** (la verdad vive en `model_has_roles`, con el tenant en la PK; `usuarios.id_rol` es cache). Los permisos llegan al usuario por rol: `usuarios` → `model_has_roles` → `roles` → `role_has_permissions` → `permissions`. La autorización se resuelve **por permiso** (`can('ordenes.cobrar')`), nunca por nombre de rol. `autorizacion_pins` es 1:1 por (usuario, establecimiento) y `autorizacion_intentos` registra los overrides fallidos. `usuarios` es actor de múltiples flujos: 1:N con `ordenes` (quién atiende), `pagos` (quién cobra), `tickets` (quién imprime), `movimientos_inventario` (quién mueve stock) y `sesiones_caja` (apertura y cierre). En `autorizaciones` participa dos veces: como solicitante y como autorizador.

**Ciclo de caja y venta.** `sesiones_caja` 1:N `ordenes`. Una `orden` pertenece a un `establecimiento`, una `sesion_caja`, opcionalmente una `mesa` (barra/llevar no usan mesa), un `tipo_orden` y un `usuario`. La `orden` es el hub de la venta: 1:N con `detalle_orden`, `pagos` y `tickets`, y 0..N con `movimientos_inventario` (descuentos por venta). La regla "una orden abierta por mesa" se respalda con índice único parcial.

**Catálogo de productos e inventario.** `categorias_producto` 1:N `productos`. `productos` 1:N `detalle_orden`. El inventario gira en torno a `insumos`: `unidades_medida` 1:N `insumos`, `proveedores` 0..N `insumos`. La relación producto↔insumo es N:M resuelta por la tabla puente `recetas_producto` (con atributo `cantidad`). Todo cambio de stock vive en `movimientos_inventario` (1:N desde `insumos`), que opcionalmente referencia la `orden` que lo originó.

**Gobernanza.** `autorizaciones` se vincula a las operaciones que aprueba mediante `detalle_orden.id_autorizacion` y `movimientos_inventario.id_autorizacion`, además de su referencia polimórfica `entidad`/`entidad_id`. `auditoria` es transversal: registra acciones de cualquier tabla mediante `entidad`/`entidad_id`, asociando opcionalmente el `establecimiento` y el `usuario` responsables.

**Impresión.** `impresoras` 1:N `tickets`; un `ticket` puede no tener impresora asignada (`id_impresora` NULL), caso en que el documento se exporta a PDF.
