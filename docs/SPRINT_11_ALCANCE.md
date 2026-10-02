# SPRINT_11_ALCANCE.md — Reportes y Dashboard

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Fecha:** 2026-07-01
**Base:** `RoadmapImplementacion.md` (Sprint 11, §4 Pruebas §534–537, §5 DoD), `EspecificacionFuncional.md` (M16, CU de reportes, reglas globales, P20/P21), `DatabaseDictionary.md` (`ordenes`, `detalle_orden`, `pagos`, `insumos`, `movimientos_inventario`, `sesiones_caja`, `autorizaciones`, `auditoria`, `configuracion_establecimiento`, `establecimientos.zona_horaria`). Arranca sobre **todo el núcleo transaccional** ya cerrado: Caja (S6), Órdenes (S7), Pagos + descuento de inventario (S8), Autorizaciones (S9) e Impresión/Tickets (S10).

> **Contexto.** El Sprint 11 entrega la **capa de lectura** del sistema: dashboard del día y reportes de gestión (ventas, inventario, caja, medios de pago, cancelaciones, margen), más la consulta de **auditoría** (admin por tenant, super_admin global). Todo se construye con **Query Services de sólo lectura** que **no mutan estado**, se filtran por `id_establecimiento`, respetan el **alcance por rol** (P21: el operador sólo ve su turno) y calculan los rangos en la **zona horaria del establecimiento**. La exportación a **PDF/Excel** se **encola**. Cierra el paso 10 de §23. Es de nuevo **capa de aplicación**, pero con la primera incorporación de **dependencias de terceros** desde el S0 (dompdf, Laravel Excel).

---

## 0. Punto de partida (lo que ya existía de sprints previos)

- **Fuentes de datos ya pobladas y auditadas** (S6–S10): `sesiones_caja` (arqueo, diferencias, motivo), `ordenes`/`detalle_orden` (folio, totales congelados, estados, cancelaciones vía `id_autorizacion`), `pagos` (medios de pago, `id_usuario`), `movimientos_inventario` (ledger append-only: entrada/ajuste/merma/rotura/consumo/venta), `insumos` (`stock_actual`, `costo_unitario`, índice parcial de stock bajo, S5), `autorizaciones`, `auditoria`.
- **Vínculos de alcance ya presentes** (S0): `ordenes.id_sesion_caja` (FK → `sesiones_caja`), `ordenes.id_usuario` (usuario que atiende), `pagos.id_usuario` (usuario que cobró), `sesiones_caja.id_usuario_apertura`/`id_usuario_cierre`. Bastan para acotar el reporte del operador a **su turno** sin columnas nuevas.
- **Fuente de costo del margen ya modelada** (S4/S5): `productos.controla_inventario` (bandera), `productos.costo_referencia` (costo de gestión, DECIMAL 12,2) y `recetas_producto` + `insumos.costo_unitario` (costo por insumos). P20 resuelto **por producto**; no requiere columna nueva.
- **Zona horaria** (S2): `establecimientos.zona_horaria` VARCHAR(50) (ej. `America/Mexico_City`). Los `timestamps` se almacenan en UTC.
- **Permisos ya sembrados** (`RolesPermisosSeeder`, Fase 8) — **no se requiere cambio de seeder**:
  - `reportes.ver` → **ADMIN** (todo el establecimiento).
  - `reportes.ver_limitado` → **OPERADOR** (su turno/caja, P21).
  - `auditoria.ver` → **ADMIN** (auditoría del tenant); `auditoria.global` → **SUPER_ADMIN** (auditoría global).
- **Cola por defecto:** `database` (en pruebas, `sync`). La tabla `jobs` ya está migrada; el patrón de job encolado quedó validado en S8/S10.

---

## 1. Decisiones de producto ratificadas

| # | Decisión abierta | Resolución |
|---|---|---|
| D1 | **Dependencias de exportación PDF/Excel.** El roadmap nombra dompdf y Laravel Excel; hasta ahora el proyecto no tenía dependencias de dominio de terceros. | **Se instalan `barryvdh/laravel-dompdf` (PDF) y `maatwebsite/excel` (Excel)** — primeras dependencias funcionales desde el S0, justificadas por el entregable de exportación. La generación se aísla tras una interfaz `ReporteExporter` (estrategias `PdfExporter`/`ExcelExporter`), de modo que el Query Service no conoce el formato. |
| D2 | **Exportación síncrona vs. encolada, sin tabla de seguimiento.** El DER está **congelado en 22 tablas** (el roadmap no altera el DER); no se puede añadir una tabla `exportaciones`. | **Exportación encolada sin tabla nueva.** `POST /reportes/exportar` valida y **despacha `GenerarReporteExportJob`** (cola `reportes`), que escribe el archivo en `storage` **particionado por tenant** (`exportaciones/{id_establecimiento}/{uuid}.{pdf\|xlsx}`); la respuesta es **`202 Accepted`** con el `id_export` (nombre del archivo) y una **URL de descarga firmada** (ruta firmada de Laravel, sin endpoint de negocio nuevo). En pruebas la cola corre en `sync`, así que el archivo queda disponible al terminar el request y el test asserta su creación (mismo patrón "encolado que no bloquea" del S10). |
| D3 | **Fuente del costo en el reporte de margen (P20).** | **Resuelta por producto, sin columna ni migración nueva:** `controla_inventario = false` → `costo_referencia`; `controla_inventario = true` → **Σ (cantidad de receta × `insumos.costo_unitario`)**. Se usa el **costo vigente** del insumo (no el histórico por lote): el costeo histórico por movimiento queda fuera del MVP (deuda menor documentada). "Configurable" ≡ resolución automática por la bandera del producto (Fase 9, P20). |
| D4 | **Alcance exacto del OPERADOR (P21).** | El operador consulta con `reportes.ver_limitado`, **acotado a su turno**: sus **sesiones de caja** (`sesiones_caja.id_usuario_apertura = actor`) y las **órdenes/pagos** dentro de ellas (`ordenes.id_sesion_caja ∈ sus sesiones`). Su dashboard refleja **su sesión actual**; **no** ve inventario global, margen ni cancelaciones de todo el establecimiento. El **ADMIN** (`reportes.ver`) ve **todo el establecimiento** con rango de fechas libre. El acotamiento vive en el Query Service (regla de negocio), no en la policy. |
| D5 | **Interpretación de rangos de fecha (zona horaria).** | Un value object **`RangoFechas`** traduce el rango solicitado (`desde`/`hasta`, o presets `hoy`/`semana`/`mes`/`año`) a `[inicio, fin)` **en `establecimientos.zona_horaria`** y lo convierte a **UTC** para filtrar `created_at`. Sin `zona_horaria` configurada → `UTC`. Todo reporte agrega sobre este rango; calcular en UTC directo (reportes "corridos") queda prohibido por construcción. |
| D6 | **Sólo lectura.** | Los Query Services **no abren `DB::transaction` de escritura ni mutan estado**; sólo consultan. No hay eventos de dominio ni auditoría de "lectura de reporte" en el MVP (la consulta de reportes no es acción sensible). La única auditoría relevante ya existe: la de las acciones que los reportes leen. |

**Contratos ya fijados por el roadmap (se implementan, no se reabren):**
- **Todo reporte se filtra por `id_establecimiento`** (`TenantScope`); el super_admin sólo cruza tenants en `GET /auditoria/global`.
- **Exportación PDF/Excel encolada** (nunca bloquea la respuesta del reporte interactivo).
- **Lectura separada de escritura:** los Query Services no mutan estado.
- **Alcance por rol (P21)** y **zona horaria del establecimiento** obligatorios.

---

## 2. Módulos y endpoints (cadena `auth:sanctum → resolve.tenant → tenant.activo`)

| Acción | Endpoint | Permiso |
|---|---|---|
| Dashboard del día | `GET /api/v1/reportes/dashboard` | `reportes.ver` / `reportes.ver_limitado` |
| Ventas por periodo | `GET /api/v1/reportes/ventas` | `reportes.ver` / `reportes.ver_limitado` |
| Inventario (stock, stock bajo, movimientos, mermas) | `GET /api/v1/reportes/inventario` | `reportes.ver` |
| Caja (aperturas, cierres, diferencias) | `GET /api/v1/reportes/caja` | `reportes.ver` / `reportes.ver_limitado` |
| Medios de pago | `GET /api/v1/reportes/medios-pago` | `reportes.ver` / `reportes.ver_limitado` |
| Cancelaciones (usuario, motivo, fecha, entidad) | `GET /api/v1/reportes/cancelaciones` | `reportes.ver` |
| Utilidad / margen | `GET /api/v1/reportes/margen` | `reportes.ver` |
| Exportar (encolada) | `POST /api/v1/reportes/exportar` | `reportes.ver` / `reportes.ver_limitado` |
| Auditoría del tenant | `GET /api/v1/auditoria` | `auditoria.ver` |
| Auditoría global | `GET /api/v1/auditoria/global` | `auditoria.global` |

Los reportes marcados con doble permiso los ve **ambos roles con alcance distinto** (D4); `inventario`, `cancelaciones` y `margen` son **sólo ADMIN** (gestión del establecimiento, fuera del turno del operador). **No exigen `caja.abierta`**: la consulta es lectura de gestión, no una mutación del ciclo de venta.

**Dominio** (`app/Domain/Reportes`):
- `RangoFechas` — value object que resuelve presets y rango explícito a `[inicio, fin)` en la zona del establecimiento → UTC (D5).
- `AlcanceReporte` — resuelve el filtro por rol (admin = establecimiento; operador = sus sesiones de caja, D4); centraliza el `where` de acotamiento para todos los Query Services.
- **Query Services** (`app/Domain/Reportes/Queries`, sólo lectura, cada uno devuelve un DTO/array agregado):
  - `DashboardQuery` — ventas del día, órdenes abiertas/cerradas, top de productos vendidos.
  - `VentasQuery` — ventas por periodo (día/semana/mes/año) con totales e impuesto.
  - `InventarioQuery` — stock actual, stock bajo (índice parcial S5), movimientos y mermas.
  - `CajaQuery` — aperturas/cierres/diferencias con motivo (P5).
  - `MediosPagoQuery` — desglose por `tipos_pago` (P6: efectivo vs. otros).
  - `CancelacionesQuery` — cancelaciones/anulaciones (usuario, motivo, fecha, entidad; enlaza `id_autorizacion`).
  - `MargenQuery` — utilidad por la fuente de costo del producto (D3, P20).
- **Exportación:** interfaz `ReporteExporter` + estrategias `PdfExporter` (dompdf) / `ExcelExporter` (Laravel Excel); `GenerarReporteExportJob` (`ShouldQueue`, cola `reportes`, `tries=3`) que reejecuta el Query Service correspondiente y persiste el archivo por tenant (D2).

**Policies:** `ReportePolicy` (`ver` con las dos variantes de alcance), `AuditoriaPolicy` (`ver` admin/tenant, `global` super_admin).
**Form Requests:** `RangoReporteRequest` (valida `desde ≤ hasta`, presets, formato de fecha), `ExportarReporteRequest` (reporte destino ∈ catálogo, formato ∈ {`pdf`,`excel`}).
**Resources:** un Resource por reporte bajo `Api/V1/Reportes` + `AuditoriaResource`; colecciones **paginadas** (§18) en los listados (movimientos, cancelaciones, auditoría).
**Controllers:** `ReporteController`, `AuditoriaController`.

---

## 3. Contratos clave

- **Dashboard.** `GET /reportes/dashboard`: para el ADMIN, agrega el día (zona del establecimiento) de todo el tenant; para el OPERADOR, agrega **su sesión de caja actual** (D4). Tarjetas: ventas del día, órdenes abiertas/cerradas, top de productos. Sin caja abierta, el dashboard del operador refleja su última sesión (o vacío).
- **Ventas por periodo.** `GET /reportes/ventas?preset=mes|desde=&hasta=`: `RangoFechas` fija la ventana; suma `ordenes` en estado `pagada` con sus totales; el impuesto se reporta **aparte** (coherente con P11). El operador ve sólo las de sus sesiones.
- **Inventario (ADMIN).** `GET /reportes/inventario`: stock actual por insumo, subconjunto de **stock bajo** (usa el índice parcial de S5), últimos movimientos y mermas del rango. Sólo lectura del ledger.
- **Caja.** `GET /reportes/caja`: aperturas/cierres/diferencias con `motivo` (P5); `monto_sistema` sólo efectivo (P6/P7). El operador ve sus propias sesiones.
- **Medios de pago.** `GET /reportes/medios-pago`: agrupa `pagos` por `tipos_pago` en el rango; separa efectivo de tarjeta/transferencia (insumo del arqueo).
- **Cancelaciones (ADMIN).** `GET /reportes/cancelaciones`: renglones cancelados/órdenes anuladas con usuario, motivo, fecha y entidad; enlaza la autorización (`id_autorizacion`) cuando la operación provino del flujo de dos niveles (S9).
- **Margen (ADMIN).** `GET /reportes/margen`: por producto vendido en el rango, ingreso − costo; el **costo se resuelve por `controla_inventario`** (D3, P20). Devuelve margen absoluto y porcentual.
- **Exportar.** `POST /reportes/exportar` con `{reporte, formato, ...rango}`: valida, **despacha `GenerarReporteExportJob`**, responde `202` con `id_export` + URL firmada; el job aplica el **mismo alcance por rol** que el reporte interactivo (el operador no puede exportar datos fuera de su turno). Archivo particionado por tenant en `storage` (D2).
- **Auditoría.** `GET /auditoria` (admin, filtrable por fecha/acción/entidad, **paginada**, sólo su tenant); `GET /auditoria/global` (super_admin, incluye acciones sin `id_establecimiento`, p. ej. impersonación).
- **Aislamiento por tenant** (`TenantScope`): todo Query Service parte del tenant activo; el único cruce autorizado es `auditoria/global` del super_admin. Un reporte/exportación jamás mezcla establecimientos.
- **Zona horaria (D5):** los límites del rango se calculan en `establecimientos.zona_horaria`; dos establecimientos en husos distintos obtienen cortes de día distintos para el mismo instante UTC.

---

## 4. Pruebas (DoD §4 / §534–537)

- **Unit** (`tests/Unit/Reportes`):
  - `RangoFechasTest` — presets `hoy`/`semana`/`mes`/`año` y rango explícito se resuelven en la zona del establecimiento y se convierten a UTC; sin `zona_horaria` → UTC.
  - `MargenQueryTest` — costo por `controla_inventario` (referencia vs. insumos de receta, P20); margen absoluto y porcentual.
  - `VentasQueryTest` / `MediosPagoQueryTest` — agregación correcta (totales, impuesto aparte, agrupación por medio de pago).
- **Feature** (`tests/Feature/Reportes`):
  - `ReporteScopeTest` — **P21**: el operador (`reportes.ver_limitado`) sólo ve su turno; no accede a inventario/margen/cancelaciones (403); el admin ve todo el establecimiento.
  - `ReporteValidacionTest` — rango de fechas inválido (`desde > hasta`) → 422; exportación sin datos produce archivo vacío válido (no error).
  - `AuditoriaTest` — admin consulta auditoría de su tenant (paginada); operador no accede (403); super_admin consulta global.
- **Integration** (`tests/Integration/Reportes`):
  - `ExportacionFlujoTest` — `POST /reportes/exportar` **encola** `GenerarReporteExportJob` (`Queue::fake`/`sync`) y genera el archivo por tenant con el alcance del rol; no bloquea la respuesta (`202`).
  - `AislamientoReportesTest` — un reporte del tenant A no incluye datos de B; `auditoria/global` del super_admin sí cruza tenants; `auditoria` del admin no.
- **Estado esperado:** suite **verde en SQLite y PostgreSQL 17**, **Pint limpio**, **sin migraciones nuevas** (paridad por construcción; DER congelado en 22 tablas). Se parte de **205** (cierre del S10). Nota: las nuevas dependencias (dompdf, Laravel Excel) se ejercitan con la cola en `sync`; los tests de exportación verifican el archivo generado, no el binario byte a byte.

## 5. Fuera de alcance (sprint siguiente)

- **Consolidación de auditoría por Observer** en modelos maestros (CRUD diff antes/después) y **matriz de permisos completa** — Sprint 12.
- **Costeo histórico por lote/movimiento** para el margen (hoy se usa el `costo_unitario` vigente del insumo, D3) — deuda documentada, no MVP.
- **Programación/entrega de reportes** (envío por correo, agendado) y **gráficas** del lado servidor — el backend entrega datos agregados; la visualización es del front.
- **Tabla de seguimiento de exportaciones** (historial/reintentos con estado persistido): el DER está congelado; el artefacto es el archivo en `storage` (D2).

## 6. Deuda heredada (sin cambios + nota)

Decisión formal de RLS, aritmética monetaria en float (los reportes agregan importes ya congelados; mismo criterio S5–S10), unificación del patrón de auditoría (Observer en S12), riesgos del folio (S7). **Nota async-tenant:** `GenerarReporteExportJob` se prueba con cola `sync`; un worker asíncrono real necesitaría re-fijar el `TenantContext` antes de reejecutar el Query Service (mismo riesgo anotado en S10, ahora **sí aplica** porque el job reconstruye datos por scope, no sólo marca por PK). Se documenta como riesgo a resolver si se migra a Redis/SQS. **Nota deps:** primera incorporación de dependencias de terceros desde el S0 (dompdf, Laravel Excel); quedan aisladas tras `ReporteExporter` para no filtrar el vendor al dominio.
