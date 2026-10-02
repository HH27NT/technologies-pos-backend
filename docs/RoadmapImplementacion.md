# Roadmap de Implementación — SaaS POS Multi-Tenant (MVP V1)

**Producto:** SaaS POS Multi-Tenant para bares, cervecerías, cantinas, cafeterías y restaurantes pequeños
**Base documental:** DER V1.3 · Diccionario de Datos V1.3 · Especificación Funcional MVP V1 (15 módulos, Fase 7 actualizada a 5 roles el 2026-08-14) · Arquitectura Backend MVP V1
**Stack:** Laravel 12 · PostgreSQL 15+ · Laravel Sanctum · spatie/laravel-permission · React · Vite · **Tailwind + shadcn/ui**

> **⚠️ Estado del documento (2026-08-14).** Este roadmap es el **plan original de los 13 sprints
> (0–12)**, todos **completados**: el MVP V1 está terminado y el frontend cerró sus 10 fases el
> 2026-07-14. Se conserva como registro del plan y de las razones de secuenciación; **no describe
> el trabajo posterior a la v1**. Para el estado actual, ver "Trabajo posterior a la v1" al final
> de este documento y el tablero vivo en `bar-pos-web/docs/ESTADO.md`.
>
> Corrección menor: el stack de UI dice "Material UI" por herencia del plan inicial; el frontend
> se construyó con **Tailwind + shadcn/ui**.

**Propósito:** definir el **orden exacto de implementación** del sistema para minimizar retrabajos y dependencias. Este documento **no** introduce código, **no** modifica la arquitectura, **no** propone funcionalidades nuevas y **no** altera el DER. Es un plan de secuenciación sobre los artefactos ya aprobados.

> **Principio rector del roadmap.** Se construye de adentro hacia afuera: primero el aislamiento de datos (tenant), luego la identidad (auth/roles), después los catálogos estáticos que la venta consume, a continuación el núcleo transaccional (caja → órdenes → pagos → inventario), después la gobernanza (autorizaciones, auditoría) y al final la salida (impresión, reportes) y el endurecimiento. Este orden coincide con la “Ruta para iniciar desarrollo” (§23 de la Arquitectura) y la respeta literalmente; el roadmap sólo la detalla y la organiza en sprints.

---

## SECCIÓN 1 — DEPENDENCIAS ENTRE MÓDULOS

### 1.1 Mapa de capas

El sistema se organiza naturalmente en seis capas de dependencia. Una capa no puede cerrarse hasta que la anterior esté funcional, porque las reglas de negocio de la capa superior se apoyan en garantías de la inferior.

| Capa | Contenido | Razón de la posición |
|---|---|---|
| **0 · Cimientos** | Migraciones (DER + ajustes §2.1 + tablas Spatie con `teams`), seeders, infraestructura multi-tenant (`TenantContext`, `BelongsToTenant`, `TenantScope`, `IncluyeGlobales`), modelos Eloquent, relaciones, casts, traits | Todo el resto del sistema escribe y lee a través del tenant; nada puede probarse aislado sin el discriminador `id_establecimiento` operativo. |
| **1 · Identidad y administración del tenant** | M01 Autenticación, M02 Plataforma (SUPER_ADMIN), M03 Configuración, M04 Usuarios/Roles, infraestructura de Auditoría | Sin autenticación + resolución de tenant + roles no se puede autorizar ninguna acción posterior. Los establecimientos deben poder crearse para que exista contra qué probar. |
| **2 · Catálogos que consume la venta** | M05 Categorías, M06 Productos, M07 Recetas, M08 Inventario (CRUD insumos/proveedores/unidades + movimientos manuales), M09 Mesas (CRUD), M13 Impresoras (CRUD) | Una orden necesita productos, mesas y (para descontar) recetas e insumos **ya existentes**. Construir la venta sin catálogo obliga a sembrar datos a mano y genera retrabajo. |
| **3 · Núcleo transaccional** | M10 Caja, M11 Órdenes, M12 Pagos, M08 (descuento de inventario al cobrar) | Es el corazón del negocio y depende de absolutamente todo lo anterior. El descuento de inventario se dispara al cobrar (P1), por lo que llega después de Pagos. |
| **4 · Gobernanza** | M14 Autorizaciones, M15 Auditoría (consolidación) | Las autorizaciones gobiernan operaciones que sólo existen una vez que hay órdenes e inventario (cancelar ítem, anular orden, entrada/ajuste). La auditoría se cablea desde la capa 1 pero se **completa y verifica** aquí. |
| **5 · Salida y endurecimiento** | M13 Impresión (comanda/ticket/PDF), M16 Reportes/Dashboard, pruebas transversales (aislamiento, concurrencia, matriz de permisos) | Impresión y reportes consumen datos producidos por todo el núcleo; deben ir al final para reflejar el modelo completo. |

### 1.2 Dependencias explícitas (quién depende de quién)

- **M01 Autenticación** ⟷ **M04 Usuarios/Roles**: relación mutua. El *mecanismo* de auth (Sanctum, login, `ResolveTenant`, reconciliación con Spatie) sólo necesita las tablas `usuarios` y `roles` y un super_admin sembrado; el *CRUD de usuarios* (M04) necesita el mecanismo de auth ya funcional. Se separan: primero el mecanismo, después la gestión.
- **M02 Plataforma** depende de Establecimientos, Usuarios y Auditoría. Es la puerta de entrada del SaaS: crea establecimientos y siembra su configuración y su ADMIN inicial.
- **M03 Configuración** depende de **M02** (debe existir el establecimiento). Aporta `aplica_impuesto`/`tasa_impuesto`, insumo directo del `TotalizadorOrden`.
- **M04 Usuarios/Roles** depende de **M01** y Auditoría; integra los *teams* de Spatie por `id_establecimiento`.
- **M05 Categorías**: sin dependencias funcionales más allá del tenant. Catálogo base independiente.
- **M06 Productos** depende de **M05** (categoría), **M07** (receta) y **M08** (insumos).
- **M07 Recetas** depende de **M06** y **M08** (es la tabla puente producto↔insumo).
- **M08 Inventario** depende de **M14** (entradas/ajustes solicitados por operador requieren autorización), **M15** (auditoría) y **M11** (el descuento por venta lo origina la orden cobrada).
- **M09 Mesas**: el CRUD es independiente; su **estado derivado** (libre/ocupada) depende de **M11** (la orden abierta es la fuente de verdad).
- **M10 Caja** depende de **M04**, y se relaciona con **M11/M12** (calcula el arqueo a partir de los pagos en efectivo) y con Auditoría.
- **M11 Órdenes** depende de **M10** (no se vende sin caja), **M09** (mesa), **M06** (producto), **M08** (inventario), **M12** (pago) y **M14** (cancelar/anular).
- **M12 Pagos** depende de **M11**, **M10**, **M13** (ticket) y **M09** (liberación de mesa).
- **M13 Impresión** depende de **M03** (datos de ticket), **M11** y **M12**.
- **M14 Autorizaciones** depende de **M11** y **M08** (las operaciones que aprueba) y de **M15**.
- **M15 Auditoría**: transversal a todos los módulos.
- **M16 Reportes**: consume datos de todos los módulos operativos.

### 1.3 Módulos que pueden desarrollarse de forma independiente

Una vez cerrada la **capa 0 + capa 1** (tenant + auth + roles + auditoría base), estos módulos pueden avanzarse en paralelo porque su CRUD básico **no** depende del núcleo transaccional:

- **M05 Categorías** — totalmente independiente.
- **M09 Mesas (CRUD)** — independiente salvo el estado derivado, que se conecta al llegar M11.
- **M13 Impresoras (CRUD)** — independiente; la *generación* de comanda/ticket se conecta en la capa 5.
- **M08 (proveedores y unidades de medida propias)** — CRUD independiente; el descuento por venta se conecta en la capa 3.
- **M06 Productos** y **M07 Recetas** son independientes del núcleo transaccional, pero **dependen entre sí y de M08** (insumos), por lo que se planifican juntos.

> **Conclusión de dependencias.** El único camino crítico real es: **Cimientos → Auth/Tenant → Caja → Órdenes → Pagos → Descuento de inventario**. Los catálogos (categorías, productos, recetas, insumos, mesas, impresoras) alimentan ese camino y deben estar listos **antes** de que la venta se pueda probar de extremo a extremo; por eso se programan inmediatamente después de la identidad y antes del núcleo transaccional.

---

## SECCIÓN 2 — SPRINTS DE IMPLEMENTACIÓN

> Cada sprint documenta objetivo, módulos, tablas, servicios, endpoints, riesgos y entregables. Los endpoints se expresan como el mapeo REST directo (`/api/v1/...`) de las operaciones ya definidas en los casos de uso; **no** introducen funcionalidad nueva. La capa de auditoría y las policies del módulo forman parte del *Definition of Done* de cada sprint (ver Sección 5).

---

### Sprint 0 — Cimientos e infraestructura de datos

**### Objetivo**
Dejar la base ejecutable: esquema completo migrado, catálogos globales y super_admin sembrados, e infraestructura multi-tenant operativa, de modo que cualquier módulo posterior nazca ya aislado por `id_establecimiento`.

**### Módulos incluidos**
Ninguno funcional de cara al usuario. Corresponde a los pasos 1–3 de la Arquitectura §23 (migraciones + tenant + modelos).

**### Tablas involucradas**
Las 22 del DER, en el orden de dependencia de FKs: `establecimientos`, `configuracion_establecimiento`, `roles`, `tipos_orden` (GLOBAL), `tipos_pago` (GLOBAL), `unidades_medida` (GLOBAL + propias), `usuarios`, `proveedores`, `insumos`, `categorias_producto`, `productos`, `recetas_producto`, `impresoras`, `mesas`, `sesiones_caja`, `ordenes`, `detalle_orden`, `pagos`, `tickets`, `movimientos_inventario`, `autorizaciones`, `auditoria`. Más las tablas de Spatie con `teams` (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`).

**### Servicios involucrados**
Aún no hay servicios de dominio. Se entregan los componentes de soporte: `TenantContext` (singleton), `TenantScope` (global scope), `BelongsToTenant`, `IncluyeGlobales` (scope híbrido de `unidades_medida`), traits `Auditable`, `SoftDeletes`, concern `GeneraFolio`.

**### Endpoints involucrados**
Ninguno de negocio. A lo sumo un *health check* técnico.

**### Riesgos**
- **Ajustes §2.1 al DER: ya ratificados e incorporados en el DER/Diccionario V1.2** (`unidades_medida.id_establecimiento` nullable, `configuracion_establecimiento.aplica_impuesto`/`tasa_impuesto`, `pagos.propina` reservado, y `detalle_orden.enviado` BOOLEAN default `false`). Las migraciones deben crear el esquema V1.2 completo, incluida la columna `detalle_orden.enviado`.
- **Índices únicos parciales** (una caja abierta por establecimiento; una orden abierta por mesa; unicidades compuestas por tenant: folio, número de mesa, login) deben definirse desde la migración; añadirlos tarde obliga a limpiar datos.
- **Row-Level Security de PostgreSQL** (recomendado en §7): decidir si se activa ahora o se difiere; activarlo tarde es más costoso.
- Orden incorrecto de creación de FKs rompe el `migrate`.

**### Entregables**
Esquema migrado y reversible; seeders `RolesPermisosSeeder`, `CatalogosGlobalesSeeder` (tipos de orden, tipos de pago, unidades predefinidas con tenant nulo) y `SuperAdminSeeder`; los 22 modelos Eloquent con relaciones, casts y traits; infraestructura de tenant probada con un test de aislamiento mínimo.

---

### Sprint 1 — Autenticación, tenancy y autorización

**### Objetivo**
Que un usuario pueda autenticarse, que el sistema resuelva su tenant y rol, y que toda ruta posterior pueda autorizarse. Establecer la base de auditoría que el resto de los sprints irá poblando.

**### Módulos incluidos**
M01 Autenticación y Control de Acceso. Infraestructura de M15 Auditoría (trait + listener + servicio). Corresponde al paso 4 de §23.

**### Tablas involucradas**
`usuarios`, `roles`, tablas de Spatie, `auditoria`, `establecimientos` (lectura para resolver/validar el tenant).

**### Servicios involucrados**
`RegistrarAuditoriaService` y el listener síncrono `RegistrarAuditoria`. Middleware `ResolveTenant`, `EnsureTenantActivo`. `Gate::before` que concede todo al super_admin. Reconciliación roles DER ↔ Spatie (`usuarios.id_rol` como cache; `model_has_roles` como verdad), con *teams* por `id_establecimiento`.

**### Endpoints involucrados**
`POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `POST /api/v1/auth/recuperar` (recuperación de contraseña por correo), `GET /api/v1/auth/me`.

**### Riesgos**
- **P16 RESUELTO (política de contraseñas e intentos fallidos):** longitud mínima de 8 caracteres, **sin** bloqueo por intentos fallidos, implementado como configurable. Ya no bloquea el congelamiento de auth.
- **Reconciliación roles/Spatie (§8):** mantener sincronizados `usuarios.id_rol` (cache) y `model_has_roles` (verdad) sin desfasarse.
- **Teams de Spatie:** fijar el `team_id = id_establecimiento` en cada request; un fallo expone permisos cruzados entre tenants.
- **P18 (sesión sin expiración):** tokens Sanctum sin caducidad obligan a compensar con revocación explícita al desactivar usuario/establecimiento; omitirlo es un hueco de seguridad.
- **Impersonación (P17):** el override de tenant por super_admin debe quedar marcado y auditado desde el inicio del middleware.

**### Entregables**
Login/logout funcional con Sanctum; `TenantContext` poblado tras autenticar; super_admin exento del scope; matriz de permisos cargada; auditoría base escribiendo en `auditoria` dentro de transacción; policies vacías por modelo listas para irse rellenando.

---

### Sprint 2 — Plataforma y establecimientos (SUPER_ADMIN)

**### Objetivo**
Permitir al super_admin dar de alta, editar, activar/desactivar establecimientos y asignar su ADMIN inicial, sembrando configuración por defecto. Sin esto no existen tenants reales contra los cuales construir el resto.

**### Módulos incluidos**
M02 Administración de Plataforma, M03 Configuración del Establecimiento.

**### Tablas involucradas**
`establecimientos`, `configuracion_establecimiento`, `usuarios` (ADMIN inicial), `roles`/Spatie, `auditoria`.

**### Servicios involucrados**
`CrearEstablecimientoService` (crea establecimiento + configuración con `aplica_impuesto`/`tasa_impuesto` + unidades propias opcionales + usuario ADMIN inicial + rol por team; emite `EstablecimientoCreado`), `ActualizarConfiguracionService` (datos de ticket, impresión automática, stock mínimo global, tasa de impuesto). Policies: `EstablecimientoPolicy`, `ConfiguracionPolicy`.

**### Endpoints involucrados**
`GET/POST/PUT /api/v1/establecimientos`, `PATCH /api/v1/establecimientos/{id}/activar`, `POST /api/v1/establecimientos/{id}/asignar-admin`, `GET/PUT /api/v1/configuracion`.

**### Riesgos**
- **EnsureTenantActivo (P19):** al desactivar un establecimiento con caja u órdenes abiertas, las órdenes quedan **congeladas** (no hay cierre forzado); el bloqueo debe ser consistente con el núcleo transaccional que aún no existe (dejar el contrato listo para que M10/M11 lo respeten).
- Atomicidad del alta: establecimiento + configuración + ADMIN + rol deben crearse en una sola transacción; un fallo parcial deja tenants huérfanos.
- Validación fiscal (RFC/email) y unicidad.

**### Entregables**
Flujo completo de alta/edición/activación de establecimientos; configuración 1:1 editable por ADMIN; siembra automática de la configuración; auditoría de las acciones de plataforma; métricas globales y auditoría global del super_admin disponibles a nivel de lectura básica.

---

### Sprint 3 — Usuarios y roles del establecimiento

**### Objetivo**
Que cada ADMIN administre el personal de su establecimiento y sus roles, con las garantías de unicidad por tenant y la regla del último administrador activo.

**### Módulos incluidos**
M04 Usuarios y Roles.

**### Tablas involucradas**
`usuarios`, `roles`/Spatie (`model_has_roles`), `auditoria`.

**### Servicios involucrados**
`CrearUsuarioService`, `ActualizarUsuarioService` (unicidad por tenant; sincroniza rol Spatie + cache `id_rol`; **impide desactivar al único ADMIN activo**; emite `UsuarioCreado`/`UsuarioModificado`), `AsignarRolService`. Policy: `UsuarioPolicy`.

**### Endpoints involucrados**
`GET/POST/PUT /api/v1/usuarios`, `PATCH /api/v1/usuarios/{id}/activar`, `POST /api/v1/usuarios/{id}/rol`.

**### Riesgos**
- **Último ADMIN activo:** la regla “debe existir al menos un ADMIN activo” se valida en el servicio; un descuido permite dejar un tenant sin administrador.
- Desincronización entre `usuarios.id_rol` (cache) y las asignaciones reales de Spatie.
- Unicidad de email/username **por establecimiento**, no global.

**### Entregables**
CRUD de usuarios con soft delete; asignación admin/operador scoped por team; reglas de unicidad y de último admin probadas; auditoría de altas/bajas/cambios de rol.

---

### Sprint 4 — Catálogo de venta y posiciones

**### Objetivo**
Tener disponibles las entidades estáticas que la orden referencia directamente: categorías, productos, mesas e impresoras (CRUD). Avanzables en paralelo por ser independientes del núcleo transaccional.

**### Módulos incluidos**
M05 Categorías, M06 Productos (CRUD y precio/costo/disponibilidad/bandera de control de inventario), M09 Mesas (CRUD + estado derivado preparado), M13 Impresoras (CRUD).

**### Tablas involucradas**
`categorias_producto`, `productos`, `mesas`, `impresoras`. (Lectura de `establecimientos` por tenant.)

**### Servicios involucrados**
`GuardarProductoService`; servicios CRUD de categorías, mesas e impresoras (con estado derivado de mesa preparado para conectarse a Órdenes). Policies: `CategoriaPolicy`, `ProductoPolicy`, `MesaPolicy`, `ImpresoraPolicy`.

**### Endpoints involucrados**
`GET/POST/PUT/PATCH /api/v1/categorias`, `.../productos`, `.../mesas`, `.../impresoras` (con activar/desactivar por soft delete).

**### Riesgos**
- **Producto con `controla_inventario = true` sin receta:** el catálogo permite crearlo, pero su venta dependerá de la receta (que llega en el sprint siguiente); dejar claro el contrato para no bloquear ni romper.
- Unicidad de número de mesa por tenant; desactivación de categoría con productos activos (advertencia, no bloqueo).
- El **estado derivado** de la mesa no debe modelarse como columna fuente de verdad; queda como lectura derivada hasta M11.

**### Entregables**
CRUD completo de categorías, productos, mesas e impresoras, con soft delete, unicidades por tenant, policies y auditoría. Catálogo de venta listo para alimentar órdenes.

---

### Sprint 5 — Inventario base y recetas

**### Objetivo**
Construir el inventario como *ledger* (fuente de verdad), su CRUD de insumos/proveedores/unidades propias, los movimientos manuales y las recetas que vinculan producto↔insumo. Deja todo preparado para el descuento automático que llegará al cobrar.

**### Módulos incluidos**
M08 Inventario (insumos, proveedores, unidades propias, movimientos manuales), M07 Recetas (BOM).

**### Tablas involucradas**
`insumos`, `proveedores`, `unidades_medida` (propias + globales vía `IncluyeGlobales`), `movimientos_inventario`, `recetas_producto`, `productos` (vínculo), `autorizaciones` (referencia, aún sin flujo), `auditoria`.

**### Servicios involucrados**
`RegistrarMovimientoService` (entrada/ajuste/merma/rotura/consumo; append-only; actualiza `stock_actual` y `stock_resultante`; emite `MovimientoRegistrado`), `GestionarRecetaService`, `ReconciliarStockService` (recalcula `stock_actual` desde el ledger). Policies: `InsumoPolicy`, `ProveedorPolicy`, `UnidadMedidaPolicy` (sólo filas propias, no globales), `MovimientoInventarioPolicy`.

**### Endpoints involucrados**
`GET/POST/PUT/PATCH /api/v1/insumos`, `.../proveedores`, `.../unidades-medida`, `.../recetas`; `POST /api/v1/movimientos` (entrada/ajuste/merma/rotura/consumo); `GET /api/v1/insumos/{id}/kardex`.

**### Riesgos**
- **`stock_actual` como cache vs. ledger:** todo movimiento debe actualizar el cache **dentro de la misma transacción**; una desincronización corrompe stock.
- **Unidades híbridas (P4):** las consultas deben devolver globales + propias; el tenant no puede editar las globales. Un scope mal aplicado expone o bloquea unidades indebidamente.
- **Entrada/ajuste por operador (P3/§8):** aquí aún no existe el flujo de autorización (llega en el Sprint 9); definir el contrato para que el operador no incremente stock directo.
- Mermas mayores al stock: la política de stock negativo (P2) aplica al descuento por venta, no necesariamente a la merma manual; aclarar el comportamiento sin inventar reglas.

**### Entregables**
Inventario CRUD + movimientos manuales append-only con auditoría; recetas producto↔insumo con unicidad de par; reconciliación de stock; unidades híbridas funcionando; policies por entidad.

---

### Sprint 6 — Caja

**### Objetivo**
Habilitar/inhabilitar la venta mediante el ciclo de caja: una sola sesión abierta por establecimiento, cierre con arqueo de efectivo y registro de diferencia con motivo.

**### Módulos incluidos**
M10 Caja. Corresponde al paso 5 de §23.

**### Tablas involucradas**
`sesiones_caja`, `usuarios` (apertura/cierre), `pagos` (lectura para arqueo, una vez exista), `auditoria`.

**### Servicios involucrados**
`AbrirCajaService` (`lockForUpdate` sobre el establecimiento; verifica que no exista sesión abierta; emite `CajaAbierta`), `CerrarCajaService` (verifica ausencia de órdenes abiertas; `monto_sistema = monto_inicial + Σ pagos en efectivo` —P6, P7—; calcula `diferencia`; **exige `motivo` si `diferencia ≠ 0`** —P5—; **sin autorización**; emite `CajaCerrada`). Middleware `EnsureCajaAbierta`. Policy: `SesionCajaPolicy`.

**### Endpoints involucrados**
`POST /api/v1/caja/abrir`, `POST /api/v1/caja/cerrar`, `GET /api/v1/caja/actual`, `GET /api/v1/caja/historico`.

**### Riesgos**
- **Concurrencia “una caja abierta”:** dobles aperturas simultáneas deben fallar por índice único parcial + bloqueo pesimista.
- **Cierre con órdenes abiertas:** el bloqueo depende de que M11 exista; en este sprint la regla se prepara y se valida en el siguiente.
- **Arqueo sólo efectivo (P6):** sumar tarjeta/transferencia al `monto_sistema` sería un error; deben ir sólo a reporte.
- **Sin retiros/fondos (P7):** no agregar términos de salida al cálculo.

**### Entregables**
Apertura/cierre con arqueo de efectivo; cálculo de diferencia + motivo obligatorio; histórico de cajas; bloqueo de venta sin caja vía middleware; auditoría de `caja.abierta`/`caja.cerrada`.

---

### Sprint 7 — Órdenes y totalizador

**### Objetivo**
Gestionar el ciclo de vida de la orden (crear, agregar/modificar ítems, totalizar con impuesto configurable) hasta antes del cobro. La cancelación/anulación directa por ADMIN se incluye; el flujo de dos niveles para el operador llega en el Sprint 9.

**### Módulos incluidos**
M11 Órdenes (Venta). Parte del paso 6 de §23.

**### Tablas involucradas**
`ordenes`, `detalle_orden`, `mesas`, `tipos_orden`, `productos`, `configuracion_establecimiento` (tasa de impuesto), `sesiones_caja`, `auditoria`.

**### Servicios involucrados**
`CrearOrdenService` (exige caja abierta; `lockForUpdate` sobre mesa; valida una sola orden abierta por mesa; genera folio continuo por tenant con `GeneraFolio` —P12—; emite `OrdenCreada`), `AgregarItemService` (solo permite modificar cantidad de ítems con `enviado = false`), `ConfirmarComandaService` (marca ítems enviados con `enviado = true`; **no toca inventario** —P1—; emite `ItemConfirmado`), `AplicarDescuentoService` (cajero, sin autorización —P10—), `CancelarItemService` y `AnularOrdenService` (en este sprint, sólo vía actor ADMIN directo), `TotalizadorOrden` (subtotal − descuentos + **impuesto sumado aparte** —P11—). Policy: `OrdenPolicy`.

**### Endpoints involucrados**
`POST /api/v1/ordenes`, `POST /api/v1/ordenes/{id}/items`, `PUT /api/v1/ordenes/{id}/items/{itemId}`, `POST /api/v1/ordenes/{id}/comanda`, `POST /api/v1/ordenes/{id}/descuento`, `PATCH /api/v1/ordenes/{id}/items/{itemId}/cancelar` (ADMIN), `PATCH /api/v1/ordenes/{id}/anular` (ADMIN), `GET /api/v1/ordenes`, `GET /api/v1/ordenes/{id}`.

**### Riesgos**
- **Concurrencia “una orden abierta por mesa”:** índice único parcial + bloqueo pesimista; dobles creaciones deben fallar.
- **Folio continuo por tenant (P12):** la generación debe ser segura ante concurrencia (no reinicia, no duplica).
- **Impuesto sumado aparte (P11):** el `precio_venta` no incluye impuesto; un cálculo que lo incluya rompe los totales y los reportes.
- **No agregar ítems a orden pagada/anulada.**

**### Entregables**
Ciclo de orden funcional (crear → agregar/modificar → totalizar → comanda) con folio continuo, impuesto configurable y descuentos del cajero; cancelación/anulación directa por ADMIN; auditoría de eventos de orden; policy de orden.

---

### Sprint 8 — Pagos, cierre de orden y descuento de inventario al cobrar

**### Objetivo**
Cobrar la orden (simple y dividido por monto), cerrarla al saldar, y disparar el descuento de inventario, la liberación de mesa y la impresión del ticket. Cierra el camino crítico de la venta.

**### Módulos incluidos**
M12 Pagos; M08 (descuento automático por venta). Completa el paso 6 e introduce el paso 7 de §23.

**### Tablas involucradas**
`pagos`, `tipos_pago`, `ordenes`, `detalle_orden`, `movimientos_inventario`, `insumos`, `recetas_producto`, `mesas`, `tickets`, `auditoria`.

**### Servicios involucrados**
`RegistrarPagoService` (valida orden abierta y monto > 0; **pago dividido por monto** —P8—; **sin propina** —P9—; al llegar saldo a 0 cierra orden `pagada` y emite `OrdenPagada`; emite `PagoRegistrado`), `DescontarInventarioService` (disparado por `OrdenPagada` —P1—; recorre recetas; sólo si el producto `controla_inventario` y tiene receta; **permite negativo** —P2—; emite `StockBajoDetectado`), `RevertirMovimientoService` (salvaguarda, sólo movimientos de origen `venta` —P3—). Listeners `DescontarInventario`, `LiberarMesa`, `ImprimirTicket` (encolado). Policy: `OrdenPolicy` (cobrar).

**### Endpoints involucrados**
`POST /api/v1/ordenes/{id}/pagos`, `GET /api/v1/ordenes/{id}/saldo`.

**### Riesgos**
- **Idempotencia de pagos:** clave de idempotencia por intento para evitar doble cobro ante reintentos de red.
- **Descuento al cobrar (P1) + stock negativo (P2):** el servicio no debe bloquear por stock; un bloqueo accidental viola la regla.
- **Reversa (P3):** `RevertirMovimientoService` sólo actúa sobre movimientos `venta`; nunca sobre entrada/ajuste/merma. Como cancelar/anular ocurre con la orden **abierta** (antes del cobro), en el flujo normal no hay stock que revertir; es salvaguarda.
- **Atomicidad:** pago + cierre + descuento + auditoría financiera dentro de transacción; la impresión se encola (nunca bloquea el cobro).
- **Sobrepago:** en efectivo se calcula cambio (no se almacena como pago); en tarjeta/transferencia no se permite.

**### Entregables**
Cobro simple y dividido por monto; cierre automático al saldar; descuento de inventario al cobrar con alerta de stock bajo; liberación de mesa; despacho de impresión encolado; auditoría de `orden.pagada` en transacción; idempotencia de pagos probada.

---

### Sprint 9 — Autorizaciones (flujo de dos niveles)

**### Objetivo**
Materializar “operador solicita → admin autoriza → sistema ejecuta → sistema audita” para cancelación de ítem, anulación de orden y entrada/ajuste de stock solicitados por el operador.

**### Módulos incluidos**
M14 Autorizaciones; conecta M08 (entrada/ajuste con autorización) y M11 (cancelar/anular por operador). Paso 8 de §23.

**### Tablas involucradas**
`autorizaciones`, `detalle_orden` (`id_autorizacion`), `movimientos_inventario` (`id_autorizacion`), `ordenes`, `insumos`, `auditoria`.

**### Servicios involucrados**
`SolicitarAutorizacionService` (crea solicitud `pendiente`; emite `AutorizacionSolicitada`), `ResolverAutorizacionService` (ADMIN aprueba/rechaza; en aprobación invoca el servicio destino —`CancelarItemService`, `AnularOrdenService`, `RegistrarMovimientoService`— y enlaza el resultado; emite `AutorizacionResuelta`). Policy: `AutorizacionPolicy` (resolver: sólo admin). Se completa la rama “operador vía solicitud” de `OrdenPolicy` y `MovimientoInventarioPolicy`.

**### Endpoints involucrados**
`POST /api/v1/autorizaciones` (solicitar), `GET /api/v1/autorizaciones` (bandeja del admin), `PATCH /api/v1/autorizaciones/{id}/aprobar`, `PATCH /api/v1/autorizaciones/{id}/rechazar`.

**### Riesgos**
- **Solicitud ya resuelta:** una autorización aprobada/rechazada no cambia de estado (idempotencia de resolución).
- **Ejecución bajo potestad del admin:** al aprobar, el sistema ejecuta el servicio destino como si lo hiciera el admin; el enlace `entidad`/`entidad_id` + FK debe quedar consistente.
- **El operador nunca tiene el permiso directo** de `ordenes.cancelar_item`, `ordenes.anular`, `inventario.entrada`, `inventario.ajustar`; un error de policy abre un bypass.

**### Entregables**
Flujo de autorización completo y trazado a la operación ejecutada; bandeja de autorizaciones del ADMIN; rechazo sin efectos; auditoría de solicitud y resolución; policies de autorización y de operaciones sensibles cerradas.

---

### Sprint 10 — Impresión (comanda, ticket y fallback PDF)

**### Objetivo**
Generar comandas y tickets reales, enrutarlos a la impresora correspondiente y exportar a PDF cuando no haya impresora. La reimpresión no requiere autorización pero se audita.

**### Módulos incluidos**
M13 Impresión y Tickets (generación). Parte del paso 10 de §23.

**### Tablas involucradas**
`tickets`, `impresoras`, `ordenes`, `configuracion_establecimiento`, `auditoria`.

**### Servicios involucrados**
`GenerarComandaService`, `GenerarTicketService` (construyen `contenido_json` desde la configuración; despachan el Job; **si no hay impresora, generan PDF** —P15—; emiten `TicketReimpreso` en reimpresión). Jobs `ImprimirTicketJob`, `EnviarComandaJob` (cola `impresion`, con reintentos). Policy: `TicketPolicy` (imprimir/reimprimir, ambos roles, sin autorización —P14—).

**### Endpoints involucrados**
`POST /api/v1/ordenes/{id}/ticket`, `POST /api/v1/tickets/{id}/reimprimir`, `GET /api/v1/tickets/{id}` (vista previa / PDF).

**### Riesgos**
- **La impresión nunca bloquea el cobro:** ya se encola desde el Sprint 8; aquí debe completarse el consumidor sin reintroducir bloqueo.
- **Fallback PDF (P15):** si la impresora falla o no existe, el ticket queda registrado y reimprimible.
- **Reimpresión (P14):** permitida a ambos roles, pero **auditada** (`ticket.reimpreso`).

**### Entregables**
Generación de comanda y ticket con contenido JSON; enrutamiento por tipo de impresora; exportación a PDF; reimpresión auditada; jobs encolados con reintentos.

---

### Sprint 11 — Reportes y dashboard

**### Objetivo**
Entregar la información operativa y de gestión, con alcance por rol y exportación a PDF/Excel, en la zona horaria del establecimiento.

**### Módulos incluidos**
M16 Reportes y Dashboard. Cierra el paso 10 de §23.

**### Tablas involucradas**
`ordenes`, `detalle_orden`, `pagos`, `insumos`, `movimientos_inventario`, `sesiones_caja`, `autorizaciones` (lectura agregada; Query Services de sólo lectura).

**### Servicios involucrados**
Query Services de `Domain/Reportes/Queries` (dashboard del día, ventas por periodo, inventario/stock bajo/movimientos/mermas, caja con diferencias + motivo, medios de pago, cancelaciones, utilidad/margen), Exporters (`GenerarReporteExportJob`: PDF con dompdf, Excel con Laravel Excel). Policy: `ReportePolicy` (admin: establecimiento; operador: su turno/caja —P21—). Lectura de `AuditoriaPolicy` para auditoría/global.

**### Endpoints involucrados**
`GET /api/v1/reportes/dashboard`, `.../ventas`, `.../inventario`, `.../caja`, `.../medios-pago`, `.../cancelaciones`, `.../margen`; `POST /api/v1/reportes/exportar`; `GET /api/v1/auditoria` (admin), `GET /api/v1/auditoria/global` (super_admin).

**### Riesgos**
- **P20 RESUELTO (fuente del costo en el reporte de margen):** por producto — `controla_inventario = false` → `costo_referencia`; `controla_inventario = true` → costo de insumos de la receta. Implementado como configurable.
- **Alcance por rol (P21):** el operador sólo ve su turno/sesión; una fuga muestra datos de todo el establecimiento.
- **Zona horaria:** los rangos de fecha deben respetar la zona del establecimiento; calcular en UTC produce reportes corridos.
- **Lectura separada de escritura:** los Query Services no deben mutar estado.

**### Entregables**
Dashboard y reportes con filtros por tenant, periodo y rol; exportación PDF/Excel encolada; auditoría consultable por admin (tenant) y super_admin (global); reporte de margen entregado con la fuente de costo configurable.

---

### Sprint 12 — Consolidación de auditoría y endurecimiento

**### Objetivo**
Cerrar la cobertura transversal: auditoría CRUD por Observer en maestros, auditoría de impersonación y reimpresión, y la batería de pruebas de aislamiento, concurrencia y matriz de permisos. Resolver formalmente P16 y P20.

**### Módulos incluidos**
M15 Auditoría (consolidación) y endurecimiento transversal. Cubre los pasos 9 y 11 de §23 en su faceta de verificación.

**### Tablas involucradas**
`auditoria` (cobertura completa); revisión de todas las demás como sujetos auditados.

**### Servicios involucrados**
Observer + trait `Auditable` en modelos maestros (usuarios, productos, insumos, mesas, configuración, etc.); listeners de negocio ya cableados (caja, orden, pago, cancelación, autorización, mermas/ajustes, **impersonación P17**, **reimpresión P14**). `RegistrarAuditoriaService`.

**### Endpoints involucrados**
Ninguno nuevo. Verificación de los existentes bajo la matriz de permisos completa (cada rol × cada acción, incluidos los 🔐).

**### Riesgos**
- **Consistencia transaccional de la auditoría financiera:** si una acción se revierte, su auditoría también; verificar que ningún listener síncrono quede fuera de la transacción.
- **P16 y P20: ya resueltos** (ver Sprints 1 y 11). Solo resta verificar que la implementación respete las decisiones (8 caracteres sin bloqueo; costo por `controla_inventario`).
- **Aislamiento multi-tenant:** un usuario de A nunca debe obtener datos de B; las unidades muestran globales + propias.
- **Concurrencia:** dobles aperturas de caja y dobles órdenes por mesa deben fallar por índice único.

**### Entregables**
Cobertura de auditoría completa (CRUD + acciones de negocio + impersonación + reimpresión); suite de pruebas de aislamiento, concurrencia y permisos en verde; decisiones P16 y P20 documentadas; contrato de API listo para congelar.

---

## SECCIÓN 3 — ORDEN DE CONSTRUCCIÓN

Orden exacto recomendado. Cada decisión se justifica por la dependencia que evita o el retrabajo que previene. Este orden es el de la Arquitectura §23, desglosado y enriquecido con los catálogos que el núcleo transaccional consume.

```
Sprint 0  · Cimientos e infraestructura de datos
Sprint 1  · Autenticación, tenancy y autorización (+ auditoría base)
Sprint 2  · Plataforma y establecimientos (SUPER_ADMIN) + Configuración
Sprint 3  · Usuarios y roles del establecimiento
Sprint 4  · Catálogo de venta (categorías, productos, mesas, impresoras CRUD)
Sprint 5  · Inventario base y recetas
Sprint 6  · Caja
Sprint 7  · Órdenes y totalizador
Sprint 8  · Pagos, cierre de orden y descuento de inventario al cobrar
Sprint 9  · Autorizaciones (flujo de dos niveles)
Sprint 10 · Impresión (comanda, ticket, fallback PDF)
Sprint 11 · Reportes y dashboard
Sprint 12 · Consolidación de auditoría y endurecimiento
```

**Justificación, sprint por sprint:**

1. **Sprint 0 primero** porque el aislamiento por tenant es un principio de diseño no negociable (§1 de Arquitectura): cada entidad operativa se filtra por `id_establecimiento` automáticamente. Si se construye lógica antes de la infraestructura de tenant, hay que reescribirla para inyectar el scope. Las migraciones y los índices únicos parciales se definen aquí porque añadirlos tarde obliga a limpiar datos.

2. **Auth y tenancy en el Sprint 1** porque ninguna acción posterior puede autorizarse sin autenticación, resolución de tenant y roles. La auditoría base entra aquí porque §15 exige que la auditoría de acciones financieras se escriba **dentro de la misma transacción** que la acción; debe existir antes de Caja/Órdenes/Pagos para que cada módulo la cablee a medida que se construye.

3. **Plataforma y establecimientos antes que todo lo operativo** porque sin establecimientos no hay tenants reales contra los que probar. El alta siembra configuración (con la tasa de impuesto que el totalizador necesitará) y el ADMIN inicial.

4. **Usuarios después de la plataforma** porque el CRUD de personal necesita el mecanismo de auth ya funcional y un establecimiento existente; aquí se cierra la regla del último ADMIN activo.

5. **Catálogos (Sprint 4) e inventario (Sprint 5) antes del núcleo transaccional** porque una orden referencia productos y mesas, y el descuento al cobrar recorre recetas e insumos. Construir la venta sin catálogo obliga a sembrar datos manualmente y a retrabajar pruebas. Productos y recetas se planifican junto a insumos por su dependencia mutua.

6. **Caja antes que Órdenes** porque no se puede vender sin caja abierta (regla global 5; middleware `EnsureCajaAbierta`). La caja es la compuerta del flujo de venta.

7. **Órdenes antes que Pagos** porque el pago se aplica sobre una orden existente y la cierra. El totalizador (impuesto sumado aparte) debe estar resuelto antes de cobrar.

8. **Descuento de inventario junto con Pagos** porque el inventario se descuenta **al cobrar** (P1), disparado por el evento `OrdenPagada`. Colocarlo antes contradice la decisión ratificada y reintroduce la clase de inconsistencias que P1 eliminó.

9. **Autorizaciones después del núcleo** porque gobiernan operaciones que sólo existen una vez que hay órdenes e inventario (cancelar ítem, anular orden, entrada/ajuste). La cancelación/anulación directa por ADMIN ya quedó en el Sprint 7; aquí se añade la rama del operador.

10. **Impresión y reportes al final** porque consumen datos producidos por todo el núcleo. La impresión ya se encola desde el cobro (Sprint 8); aquí se completa el consumidor y el fallback PDF. Los reportes reflejan el modelo completo y aplican el alcance por rol.

11. **Consolidación y endurecimiento al cierre** para verificar aislamiento, concurrencia y la matriz de permisos completa, y para cerrar los pendientes P16 y P20 antes de congelar el contrato de API.

> **Nota sobre paralelismo.** A partir del Sprint 3 cerrado, los Sprints 4 y 5 (catálogos e inventario base) pueden avanzar en paralelo entre equipos, ya que no dependen del núcleo transaccional. El camino crítico secuencial es Caja → Órdenes → Pagos → Descuento (Sprints 6–8).

---

## SECCIÓN 4 — PRUEBAS

Pruebas mínimas que deben existir **antes de dar por terminado** cada sprint. Se alinean con la Estrategia de Pruebas (§22) y la de Validación (§17) de la Arquitectura.

### Sprint 0
- **Unit:** scopes `TenantScope` e `IncluyeGlobales` (filtran por `id_establecimiento`; globales con tenant nulo visibles); autollenado de `id_establecimiento` al crear; casts (JSONB→array, montos→decimal, booleanos).
- **Feature:** ejecución y reversa de migraciones; seeders crean catálogos globales, roles y super_admin.
- **Integration:** un modelo con `BelongsToTenant` no devuelve filas de otro tenant; `unidades_medida` devuelve globales + propias.

### Sprint 1
- **Unit:** reconciliación `usuarios.id_rol` ↔ Spatie; `Gate::before` concede todo al super_admin; política de contraseña por defecto (P16).
- **Feature:** login válido/ inválido; usuario inactivo y establecimiento desactivado no inician sesión; logout revoca token; `ResolveTenant` fija el contexto.
- **Integration:** `RegistrarAuditoria` escribe en `auditoria` dentro de la transacción; impersonación de super_admin queda marcada y auditada.

### Sprint 2
- **Unit:** `CrearEstablecimientoService` arma establecimiento + configuración + ADMIN + rol atómicamente; `ActualizarConfiguracionService` valida tasa de impuesto.
- **Feature:** sólo super_admin accede a plataforma; alta/edición/activación; validación RFC/email.
- **Integration:** `EnsureTenantActivo` bloquea endpoints operativos de un establecimiento desactivado (contrato P19); siembra de configuración por defecto.

### Sprint 3
- **Unit:** regla del último ADMIN activo; sincronización de rol Spatie + cache.
- **Feature:** CRUD de usuarios scoped por tenant; unicidad de email/username por establecimiento; el operador no accede al módulo.
- **Integration:** auditoría de altas/bajas/cambios de rol; aislamiento (ADMIN de A no ve usuarios de B).

### Sprint 4
- **Unit:** validaciones de producto (precio/costo ≥ 0, categoría obligatoria); unicidad de número de mesa por tenant.
- **Feature:** CRUD de categorías/productos/mesas/impresoras con policies (sólo ADMIN); soft delete; advertencia al desactivar categoría con productos activos.
- **Integration:** catálogo aislado por tenant; producto con `controla_inventario=true` sin receta queda marcado según contrato.

### Sprint 5
- **Unit:** `RegistrarMovimientoService` actualiza `stock_actual` y `stock_resultante` y es append-only; unicidad de par producto+insumo en recetas; `ReconciliarStockService` recalcula desde el ledger.
- **Feature:** CRUD insumos/proveedores/unidades; el tenant no edita unidades globales; el operador registra merma pero no entrada/ajuste directo.
- **Integration:** stock como ledger (cache coincide con la suma de movimientos); unidades híbridas globales + propias.

### Sprint 6
- **Unit:** `CerrarCajaService` calcula `monto_sistema` sólo con efectivo (P6, P7) y exige motivo si `diferencia ≠ 0` (P5).
- **Feature:** abrir/cerrar caja; bloqueo de venta sin caja (`EnsureCajaAbierta`); caja cerrada no se reabre.
- **Integration / Concurrencia:** dobles aperturas simultáneas fallan por índice único parcial + bloqueo; histórico de cajas.

### Sprint 7
- **Unit:** `TotalizadorOrden` suma el impuesto aparte sobre `precio_venta` sin impuesto (P11); `GeneraFolio` produce correlativo continuo por tenant (P12); descuento del cajero sin autorización (P10).
- **Feature:** crear orden mesa/barra; agregar/modificar ítems; no agregar ítems a orden pagada/anulada; comanda no toca inventario (P1).
- **Integration / Concurrencia:** dobles órdenes en una mesa fallan por índice único parcial; auditoría de eventos de orden.

### Sprint 8
- **Unit:** pago dividido por monto hasta saldar (P8); sin propina (P9); cálculo de cambio en sobrepago efectivo; idempotencia de pago.
- **Feature:** cobro simple y dividido; cierre al saldo cero; no cobrar orden ya pagada; sobrepago en tarjeta no permitido.
- **Integration:** `OrdenPagada` dispara descuento de inventario (recorre recetas; permite negativo, P2), libera mesa y encola impresión; auditoría financiera dentro de la transacción; reversa sólo de movimientos `venta` (P3).

### Sprint 9
- **Unit:** una solicitud resuelta no cambia de estado; `ResolverAutorizacionService` invoca el servicio destino al aprobar.
- **Feature:** operador solicita, admin aprueba/rechaza; el operador no posee permiso directo de cancelar/anular/entrada/ajuste; rechazo sin efectos.
- **Integration:** la operación ejecutada queda enlazada (`detalle_orden.id_autorizacion`, `movimientos_inventario.id_autorizacion`); auditoría de solicitud y resolución.

### Sprint 10
- **Unit:** construcción de `contenido_json` desde la configuración; selección de impresora por tipo.
- **Feature:** generar comanda/ticket; reimpresión sin autorización (P14) pero auditada; permisos de impresión para ambos roles.
- **Integration:** la impresión no bloquea el cobro (job encolado, reintentos); sin impresora → exporta PDF (P15) y el ticket queda reimprimible.

### Sprint 11
- **Unit:** Query Services agregan correctamente; reporte de margen con fuente de costo configurable (P20); rangos en la zona horaria del establecimiento.
- **Feature:** alcance por rol (operador sólo su turno/caja, P21; admin todo el establecimiento); rango de fechas inválido y exportación sin datos.
- **Integration:** exportación PDF/Excel encolada; auditoría consultable por admin (tenant) y super_admin (global).

### Sprint 12
- **Unit:** Observer + `Auditable` registran diff antes/después en JSONB para maestros.
- **Feature:** matriz de permisos completa (cada rol × cada acción, incluidos los 🔐); impersonación y reimpresión auditadas.
- **Integration / Aislamiento / Concurrencia:** un usuario de A nunca obtiene datos de B; reversa de auditoría junto a la acción; dobles aperturas/órdenes fallan.

---

## SECCIÓN 5 — CRITERIOS DE TERMINACIÓN (DEFINITION OF DONE)

Un sprint se considera terminado **sólo cuando se cumplen todos** los siguientes criterios. Son acumulativos y aplican a cada sprint salvo donde un criterio no tenga objeto (p. ej., no hay policies en el Sprint 0).

1. **Código funcionando.** Las funcionalidades del sprint operan de extremo a extremo en el flujo previsto por los casos de uso.
2. **Tests pasando.** Las pruebas Unit, Feature e Integration de la Sección 4 para ese sprint están escritas y en verde; sin reducción de cobertura respecto al sprint anterior.
3. **Aislamiento multi-tenant verificado.** Toda entidad operativa del sprint se filtra por `id_establecimiento`; existe al menos una prueba que demuestra que el tenant A no ve datos del tenant B (catálogos globales y unidades híbridas se comportan según §7).
4. **Policies implementadas.** Cada modelo gobernado por el sprint tiene su policy (§9) mapeada a `can('permiso')`, con `Gate::before` para super_admin; las reglas de *estado de negocio* viven en los servicios, no en las policies.
5. **Auditoría implementada.** Las acciones sensibles del sprint registran en `auditoria` (usuario, acción, entidad, antes/después, ip, momento); la auditoría de acciones financieras se escribe **dentro de la misma transacción** que la acción (§15).
6. **Transaccionalidad e integridad.** Todo servicio de escritura del sprint envuelve su lógica en `DB::transaction`; los bloqueos pesimistas y los índices únicos parciales que apliquen (una caja abierta, una orden abierta por mesa, folio/login/mesa por tenant) están en su lugar y probados.
7. **Validación en dos capas.** La validación de *forma* está en Form Requests (§17); la de *estado de negocio* está en los servicios y lanza las excepciones de dominio definidas (§20) con sus códigos HTTP y mensajes.
8. **Serialización por contrato.** Las salidas usan API Resources bajo `Api/V1`, sin exponer columnas internas; las colecciones están paginadas (§18).
9. **Documentación actualizada.** El contrato de los endpoints del sprint, las decisiones de la Fase 9 que materializa y cualquier supuesto quedan documentados; los pendientes que afectan al sprint (P16 en Auth, P20 en Reportes) están señalados o resueltos.
10. **Reglas globales respetadas.** Las reglas funcionales globales aplicables (Fase 8) y las decisiones ratificadas (Fase 9) que toca el sprint están implementadas tal como fueron confirmadas, sin reinterpretaciones.

> **Compuerta de cierre del proyecto.** Los ajustes al DER de §2.1 (incluida `detalle_orden.enviado`), P16 (contraseñas) y P20 (costo de margen) **ya están ratificados e incorporados en el DER V1.2 y la documentación**. Antes de congelar el contrato de API y declarar el MVP V1 terminado, solo resta confirmar la reconciliación roles/Spatie de §8 y verificar que la implementación respete estas decisiones.

---

## CIERRE — Plan oficial de desarrollo

Este roadmap traduce el DER V1.2, el Diccionario de Datos, la Especificación Funcional MVP V1 y la Arquitectura Backend en una secuencia de **trece sprints (0–12)** que respeta el orden de construcción ya ratificado por la arquitectura (§23) y lo organiza para minimizar dependencias y retrabajo. El camino crítico —cimientos, identidad, catálogos, y el núcleo Caja → Órdenes → Pagos → Inventario— está aislado y secuenciado; la gobernanza (autorizaciones, auditoría) y la salida (impresión, reportes) se apoyan sobre un núcleo ya estable; y el endurecimiento final verifica aislamiento, concurrencia y permisos antes de congelar el contrato. Cada sprint trae sus tablas, servicios, endpoints, riesgos, pruebas y criterios de terminación, de modo que el documento puede adoptarse directamente como plan de desarrollo del proyecto.

---

# TRABAJO POSTERIOR A LA V1 (agosto 2026)

> Los 13 sprints de arriba cerraron el MVP V1. Lo que sigue es **evolución de producto** sobre
> un sistema en producción, no continuación del plan original. Registro cronológico completo en
> `bar-pos-web/docs/CHANGELOG-FASES.md`; decisiones y razones en
> `bar-pos-web/docs/HANDOFF-modelo-roles.md`; contrato rol↔permiso en
> `bar-pos-web/docs/MatrizRoles.md`.

| Bloque | Estado | Cierre | Impacto en el esquema |
|---|---|---|---|
| Override de autorización por PIN (M14.1) | ✅ | 2026-07-31 | +`autorizacion_pins`, +`autorizacion_intentos`, `autorizaciones.metodo` |
| Modelo de 5 roles (`gerente`, `mesero`) | ✅ | 2026-08-05 | ninguno (permisos = datos) |
| Atribución y traspaso del mesero | ✅ | 2026-08-06 | ninguno (usa `ordenes.id_usuario`) |
| Catálogo de roles centralizado (`CatalogoRoles`) | ✅ | 2026-08-06 | ninguno |
| Editor de roles a medida | ✅ | 2026-08-13 | +`roles.etiqueta`, +`roles.descripcion` |
| Sincronización documental (DER/Diccionario/Fase 7) | ✅ | 2026-08-14 | — |
| **PIN de mesero** (terminal compartida) | ⬜ | — | **+`ordenes.id_mesero` (nullable)** + ajuste por tenant |

## Permisos agregados después del MVP

`mesas.ver` · `caja.ver` · `usuarios.gestionar_admins` · `roles.gestionar` · `ordenes.reasignar`.
Los dos primeros corrigieron bugs reales: sin `mesas.ver` el operador **no podía abrir órdenes de
mesa**, y sin `caja.ver` el mesero recibía 403 en `GET /caja/actual` y el POS lo interpretaba como
"caja cerrada", **impidiéndole vender**. Lección registrada: un permiso de gestión no sirve como
permiso de lectura para el POS.

## Cómo se agrega un rol o un permiso hoy

1. Editar `app/Domain/Usuarios/CatalogoRoles.php` — **fuente única** (permisos, paquetes y mapa
   TENANT). El seeder, `ProveedorRolesTenant`, `RolesAsignables`, el comando de sincronización y
   la creación de establecimientos derivan todos de ahí.
2. Actualizar el contrato en `bar-pos-web/docs/MatrizRoles.md` y la Fase 7 de la
   Especificación Funcional.
3. Si es un rol preset nuevo, agregar su etiqueta legible en
   `bar-pos-web/src/features/roles/etiquetas.ts`.
4. Desplegar con `docker compose --env-file .env.docker up -d --build`: el entrypoint corre
   `roles:sincronizar` en **cada arranque**, así que el cambio alcanza solo a los tenants nuevos
   **y** a los existentes.

> **Deuda registrada:** `roles:sincronizar` en cada arranque es O(tenants × roles). Con pocos
> establecimientos es instantáneo; con miles hay que moverlo a un job en cola o condicionarlo a
> un hash de la matriz. Es la compensación aceptada por no depender de que alguien recuerde
> correr el comando.
