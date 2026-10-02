# SPRINT_9_ALCANCE.md — Autorizaciones (flujo de dos niveles)

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Fecha:** 2026-06-29
**Base:** `RoadmapImplementacion.md` (Sprint 9, §4 Pruebas §524–527, §5 DoD), `EspecificacionFuncional.md` (M14, CU-08/CU-13/CU-16, matriz Fase 7, reglas globales 21–23), `DatabaseDictionary.md` (`autorizaciones`, `detalle_orden.id_autorizacion`, `movimientos_inventario.id_autorizacion`). Arranca sobre **Órdenes (S7)**, **Inventario (S5)** y **Pagos (S8)**.

> **Contexto.** El Sprint 9 materializa el flujo **"operador solicita → admin autoriza → sistema ejecuta el servicio destino → sistema audita"** (regla global 21–23) para las operaciones sensibles 🔐 de la matriz Fase 7: **cancelar ítem**, **anular orden**, **entrada de stock** y **ajuste de stock**. Reutiliza los servicios ya construidos (`CancelarItemService`, `AnularOrdenService`, `RegistrarMovimientoService`) sin reescribirlos: al aprobar, el sistema los invoca **bajo la potestad del admin** y enlaza el resultado vía `id_autorizacion`.

---

## 0. Punto de partida (lo que ya existía de sprints previos)

- **Tabla `autorizaciones`** (S0): `id_usuario_solicita`, `id_usuario_autoriza` (nullable), `tipo` VARCHAR(50) (lista abierta), `entidad`/`entidad_id` (referencia polimórfica, sin FK), `estado` ENUM(`pendiente`,`aprobada`,`rechazada`) default `pendiente`, `motivo`, `resuelta_at`. Índice `idx_autorizaciones_entidad`.
- **FK de enlace ya presentes** (S0): `detalle_orden.id_autorizacion` y `movimientos_inventario.id_autorizacion` (nullable, `constrained('autorizaciones')`). Los modelos ya exponen la relación `autorizacion()`.
- **Permiso `autorizaciones.aprobar`** sembrado y asignado solo al **ADMIN** (matriz Fase 7).
- **El OPERADOR NO tiene** `ordenes.cancelar_item`, `ordenes.anular`, `inventario.entrada`, `inventario.ajustar` (verificado en `RolesPermisosSeeder`): los endpoints directos ya le devuelven **403**. S9 añade **únicamente** la rama de solicitud; no toca la potestad directa del admin.
- **Servicios destino** (S7/S5): `CancelarItemService::cancelar(Orden, DetalleOrden, array)`, `AnularOrdenService::anular(Orden, array)`, `RegistrarMovimientoService::registrar(array)`. Todos transaccionales y auditados (§15).

---

## 1. Decisiones de producto ratificadas

| # | Decisión abierta | Resolución |
|---|---|---|
| D1 | **¿Dónde se guardan los parámetros de la operación solicitada hasta la aprobación?** La tabla `autorizaciones` solo tiene `motivo`; una solicitud de **entrada/ajuste** necesita además `id_insumo`, `cantidad` y `costo_unitario`, que no existen aún cuando la solicitud está `pendiente` (el movimiento se crea al aprobar). | **Añade 1 migración portable** (la 3ª desde S0, análoga a `motivo` del S6 y al índice del S8): columna **`datos` JSONB nullable** en `autorizaciones`, que conserva el *payload* de la operación pendiente (p. ej. `{id_insumo, cantidad, costo_unitario, tipo}` o `{id_orden, id_item}`). Es una columna, no cambia la forma del resto de la tabla, y se verifica el ciclo `up/down/fresh` en pgsql. |
| D2 | **¿Quién puede solicitar y con qué permiso?** | **Nuevo permiso `autorizaciones.solicitar`**, asignado al **OPERADOR** (y al ADMIN por herencia de catálogo no: el ADMIN ejecuta directo, no necesita solicitar). El ADMIN resuelve con `autorizaciones.aprobar`. La bandeja (`index`) es del ADMIN (`autorizaciones.aprobar`). |
| D3 | **Motivo obligatorio en la solicitud.** | **`motivo` requerido en toda solicitud** (es la justificación que ve el admin en la bandeja), independientemente de que la operación destino lo exija o no. La `entrada` directa no exige motivo (S5), pero **vía autorización sí**, por trazabilidad. |

**Contratos ya fijados por el roadmap (se implementan, no se reabren):**
- **El operador nunca tiene el permiso directo** de las cuatro operaciones 🔐 (ya garantizado por el seeder; S9 no lo altera).
- **Una solicitud resuelta no cambia de estado** (idempotencia de resolución): aprobar/rechazar una autorización `aprobada`/`rechazada` → 422.
- **Al aprobar, el sistema ejecuta el servicio destino** como si lo hiciera el admin (`Auth::id()` = admin en el movimiento/auditoría) y enlaza el resultado vía `id_autorizacion` (en `detalle_orden`/`movimientos_inventario`) y vía `entidad`/`entidad_id` (en la propia autorización).
- **Rechazo sin efectos**: marcar `rechazada` no ejecuta nada.

---

## 2. Módulos y endpoints (cadena `auth:sanctum → resolve.tenant → tenant.activo`)

| Acción | Endpoint | Permiso |
|---|---|---|
| Solicitar autorización (operador) | `POST /api/v1/autorizaciones` | `autorizaciones.solicitar` |
| Bandeja del admin (lista) | `GET /api/v1/autorizaciones` | `autorizaciones.aprobar` |
| Aprobar (ejecuta el destino) | `PATCH /api/v1/autorizaciones/{id}/aprobar` | `autorizaciones.aprobar` |
| Rechazar (sin efectos) | `PATCH /api/v1/autorizaciones/{id}/rechazar` | `autorizaciones.aprobar` |

**No exigen `caja.abierta`**: una autorización es gobernanza, no una mutación del ciclo de venta abierto; el servicio destino (cancelar/anular) ya opera sin compuerta de caja, igual que en S7.

**Servicios** (`app/Domain/Autorizaciones/Services`, transaccionales y auditados §15):
- `SolicitarAutorizacionService` — valida el tipo (enum `TipoAutorizacion`) y la existencia del sujeto **dentro del tenant** (`findOrFail` con `TenantScope` → 404 si es de otro tenant); crea la autorización `pendiente` con `entidad`/`entidad_id`, `motivo` y `datos` (payload); emite `AutorizacionSolicitada`; audita `autorizacion.solicitada`.
- `ResolverAutorizacionService` — `aprobar`/`rechazar` bajo `lockForUpdate`; **idempotencia** (si no está `pendiente` → `AutorizacionYaResueltaException` 422). En **aprobación** invoca el servicio destino según `tipo`, pasándole `id_autorizacion` para el enlace; fija `estado=aprobada`, `id_usuario_autoriza`, `resuelta_at`; audita `autorizacion.aprobada`; emite `AutorizacionResuelta`. En **rechazo**: `estado=rechazada` sin efectos colaterales; audita `autorizacion.rechazada`.

**Policy:** `AutorizacionPolicy` (`solicitar` → operador; `viewAny`/`aprobar`/`rechazar` → admin). El aislamiento por tenant lo garantiza el `TenantScope`.
**Form Requests:** `SolicitarAutorizacionRequest` (forma polimórfica por `tipo`), `RechazarAutorizacionRequest` (motivo de rechazo opcional).
**Resource:** `AutorizacionResource`.
**Eventos:** `AutorizacionSolicitada`, `AutorizacionResuelta`.
**Excepciones de dominio:** `AutorizacionYaResueltaException` (422), `AutorizacionInvalidaException` (422, sujeto en estado no solicitable).

**Cambios mínimos a servicios destino (compatibles hacia atrás):**
- `CancelarItemService` y `RegistrarMovimientoService` aceptan un `id_autorizacion` opcional en su `array $datos` y lo persisten en la fila afectada (`detalle_orden`/`movimientos_inventario`). Sin `id_autorizacion` se comportan exactamente como en S7/S5 (la rama ADMIN directo no cambia).
- `AnularOrdenService` no cambia: el enlace anular↔autorización es unidireccional vía `autorizaciones.entidad='ordenes'`/`entidad_id` (la tabla `ordenes` no tiene `id_autorizacion`).

---

## 3. Contratos clave

- **Solicitar (operador).** `tipo ∈ {cancelar_item, anular_orden, entrada_stock, ajuste_stock}`. Según el tipo, el payload `datos` lleva: `cancelar_item` → `{id_orden, id_item}` (sujeto = `detalle_orden:id_item`); `anular_orden` → `{id_orden}` (sujeto = `ordenes:id_orden`); `entrada_stock`/`ajuste_stock` → `{id_insumo, cantidad, costo_unitario?, tipo}` (sujeto = `insumos:id_insumo`). `motivo` requerido (D3). Validación de forma en el Request; existencia/estado del sujeto en el servicio (404 cross-tenant; 422 si el sujeto no es solicitable, p. ej. orden ya pagada o ítem ya cancelado).
- **Aprobar (admin).** `lockForUpdate` sobre la autorización; si no está `pendiente` → 422. Mapea `tipo → servicio destino` e invoca con `id_autorizacion`:
  - `cancelar_item` → `CancelarItemService::cancelar(orden, item, ['motivo', 'id_autorizacion'])` → marca `detalle_orden.id_autorizacion`.
  - `anular_orden` → `AnularOrdenService::anular(orden, ['motivo'])`.
  - `entrada_stock`/`ajuste_stock` → `RegistrarMovimientoService::registrar(['id_insumo','tipo'∈{entrada,ajuste},'cantidad','costo_unitario','motivo','id_autorizacion'])` → marca `movimientos_inventario.id_autorizacion`.
  Si el servicio destino lanza una excepción de estado (p. ej. la orden dejó de ser modificable), **toda la transacción de aprobación se revierte** y la autorización sigue `pendiente`.
- **Rechazar (admin).** `estado=rechazada`, sin ejecutar el destino; el ítem/orden/insumo permanece intacto.
- **Atomicidad (§15).** Resolución + ejecución del destino + auditoría viven en **una sola transacción**. Los servicios destino abren su propia `DB::transaction` (savepoint anidado, ya validado en S8).
- **Aislamiento por tenant** (`TenantScope`): autorizaciones, sujetos y movimientos por establecimiento; sujeto de otro tenant → 404.

---

## 4. Pruebas (DoD §4 / §524–527)

- **Unit** (`tests/Unit/Autorizaciones`):
  - `ResolverAutorizacionServiceTest` — una solicitud resuelta no cambia de estado (422); aprobar invoca el servicio destino (cancelar/entrada) y enlaza `id_autorizacion`; rechazar no produce efectos.
- **Feature** (`tests/Feature/Autorizaciones`):
  - `AutorizacionTest` — operador solicita (201); operador NO aprueba (403); admin aprueba/rechaza; el operador NO posee el permiso directo (cancelar/anular/entrada/ajuste → 403); rechazo sin efectos.
- **Integration** (`tests/Integration/Autorizaciones`):
  - `AutorizacionFlujoTest` — la operación ejecutada queda **enlazada** (`detalle_orden.id_autorizacion`, `movimientos_inventario.id_autorizacion`); auditoría de solicitud y resolución dentro de la transacción; aislamiento entre tenants.
- **Estado esperado:** suite **verde en SQLite y PostgreSQL 17**, **Pint limpio**, migración con ciclo `up/down/fresh` verificado en pgsql. Se parte de **176** (cierre del S8).

## 5. Fuera de alcance (sprints siguientes)

- **Impresión/reimpresión y su autorización** (`tickets.reimprimir` 🔐) — Sprint 10.
- **Reportes de autorizaciones** — Sprint 11.
- **Caducidad de solicitudes** (la EspecFuncional la menciona como caso especial): no hay vencimiento automático en V1; una solicitud `pendiente` permanece hasta resolverse.
- **Deduplicación de solicitudes** (varias `pendiente` sobre el mismo sujeto): no se bloquea en V1; el admin resuelve la que corresponda y el re-disparo del destino sobre un sujeto ya resuelto se revierte por estado.

## 6. Deuda heredada (sin cambios + nota)

Decisión formal de RLS, aritmética monetaria en float, unificación del patrón de auditoría (Observer en S12), riesgos del folio (S7). **Nota:** se aprovecha S9 para añadir los asserts de eventos pendientes (`AutorizacionSolicitada`/`AutorizacionResuelta` con `Event::fake`), saldando parte del hilo R3 abierto desde S6/S7/S8.
