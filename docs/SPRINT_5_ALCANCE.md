# SPRINT_5_ALCANCE.md — Inventario base y recetas

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Fecha:** 2026-06-17
**Base:** `RoadmapImplementacion.md` (Sprint 5, §4 Pruebas, §5 DoD). Arranca sobre la **capa 2 ya completa** (catálogo de venta — Sprint 4) y la **capa 1** (identidad/tenant).

> **Contexto.** El Sprint 5 construye el **inventario como ledger** (fuente de verdad), su CRUD de insumos/proveedores/unidades propias, los movimientos manuales y las recetas (BOM) que vinculan producto↔insumo. Deja todo preparado para el descuento automático que llegará al cobrar (Sprint 8).

---

## 0. Punto de partida (lo que ya existía del Sprint 0)

El esquema y los modelos de las 5 tablas del sprint **ya están creados y verificados en PostgreSQL** desde el Sprint 0; **este sprint no añade migraciones**, por lo que la paridad SQLite↔PostgreSQL no varía:

- Tablas: `insumos`, `proveedores`, `unidades_medida` (híbrido P4), `movimientos_inventario` (ledger append-only con `stock_resultante`), `recetas_producto` (UNIQUE `id_producto,id_insumo`).
- Modelos con relaciones, casts y traits: `Insumo` (`Auditable`, `BelongsToTenant`, `SoftDeletes`), `Proveedor`, `UnidadMedida` (`IncluyeGlobales`), `MovimientoInventario`, `RecetaProducto`.
- Índice parcial `idx_insumos_stock_bajo_parcial` (PostgreSQL) ya en migración.
- Permisos ya sembrados: `insumos.gestionar`, `proveedores.gestionar`, `unidades.gestionar`, `recetas.gestionar`, `inventario.entrada`, `inventario.ajustar`, `inventario.merma`.

El Sprint 5 es, por tanto, **capa de aplicación**: servicios de dominio, controllers, Form Requests, Resources, Policies, eventos, excepciones de dominio, factories y pruebas.

---

## 1. Decisiones de producto ratificadas (Fase 9 / roadmap)

| # | Decisión abierta | Resolución |
|---|---|---|
| D1 | **Salida manual (merma/rotura/consumo) mayor al stock disponible** | **Se bloquea** con `StockInsuficienteException` (422). El stock negativo queda reservado a la **venta** (P2), que no debe frenarse; una salida manual que excede el stock se trata como error de captura. El ledger sigue permitiendo negativo a nivel BD como salvaguarda de la venta. |
| D2 | **Stock inicial de un insumo nuevo** | Se carga **vía movimiento `entrada`**. El alta crea el insumo con `stock_actual = 0`; toda existencia nace de un movimiento auditado y queda explicada en el kardex. El CRUD de insumo **no edita `stock_actual` directamente**. |
| D3 | **Permisos del operador en inventario** (ya en seeder, ratificado) | El OPERADOR puede registrar `merma`/`rotura`/`consumo_interno` (`inventario.merma`); **no** puede `entrada`/`ajuste` (requieren `inventario.entrada`/`inventario.ajustar`, solo ADMIN). El flujo de autorización del operador para entrada/ajuste llega en el **Sprint 9**; aquí el operador simplemente carece del permiso directo (403). |

---

## 2. Módulos y endpoints (cadena `auth:sanctum → resolve.tenant → tenant.activo`)

| Módulo | Endpoints |
|---|---|
| **M08 · Proveedores** | `GET/POST /proveedores`, `GET/PUT /proveedores/{id}`, `PATCH /proveedores/{id}/activar` |
| **M08 · Unidades de medida** (propias; globales solo lectura, P4) | `GET/POST /unidades-medida`, `GET/PUT /unidades-medida/{id}`, `PATCH /unidades-medida/{id}/activar`* |
| **M08 · Insumos** | `GET/POST /insumos`, `GET/PUT /insumos/{id}`, `PATCH /insumos/{id}/activar`, `GET /insumos/{id}/kardex` |
| **M08 · Movimientos** (ledger) | `POST /movimientos` (entrada/ajuste/merma/rotura/consumo_interno) |
| **M07 · Recetas** (BOM) | `GET/POST /recetas`, `GET/PUT /recetas/{id}`, `PATCH /recetas/{id}/activar`/`DELETE` |

\* Las unidades de medida no tienen `activo` en el DER; su baja es por `DELETE` lógico/restricción. Ver §4.

**Servicios** (`app/Domain/Inventario/Services`, transaccionales y auditados §15): `GuardarProveedorService`, `GuardarUnidadMedidaService`, `GuardarInsumoService`, `RegistrarMovimientoService`, `ReconciliarStockService`, `GestionarRecetaService`.
**Policies** (Arq §9): `ProveedorPolicy`, `UnidadMedidaPolicy` (rechaza escritura sobre globales), `InsumoPolicy`, `MovimientoInventarioPolicy` (entrada/ajuste/merma), `RecetaProductoPolicy`.
**Excepciones de dominio:** `StockInsuficienteException` (422), `UnidadGlobalNoEditableException` (422), `RecetaDuplicadaException` (409).
**Eventos:** `MovimientoRegistrado`, `StockBajoDetectado` (consumido por reportes/alertas en Sprint 11).

---

## 3. Contratos clave

- **Stock como ledger (fuente de verdad).** Cada movimiento es append-only; `RegistrarMovimientoService` actualiza `insumos.stock_actual` (cache) y fija `stock_resultante` **dentro de la misma transacción** que el insert. `ReconciliarStockService` recalcula `stock_actual` como la suma firmada del ledger.
- **Signo por tipo.** `entrada` y `ajuste` (positivo) suman; `merma`, `rotura`, `consumo_interno` restan; `venta`/`salida` quedan para el Sprint 8 (descuento automático), no se exponen en `POST /movimientos`.
- **Validación de movimiento:** `cantidad > 0` siempre; `motivo` obligatorio en `merma` y `ajuste` (Especificación Funcional, validaciones M08).
- **Unidades híbridas (P4):** las consultas devuelven globales (`id_establecimiento` NULL) + propias; el tenant no puede editar ni borrar las globales.
- **Recetas:** par `producto+insumo` único (índice + servicio); ambos deben pertenecer al tenant; `cantidad > 0`.
- **Auditoría:** alta/edición/baja de insumos, proveedores, unidades y recetas, y cada movimiento, se registran en `auditoria` dentro de la transacción (vía servicio, igual que el Sprint 4; la consolidación por Observer es del Sprint 12).

---

## 4. Decisiones de contrato menores

- **Unidades de medida sin `activo`/`SoftDeletes` en el DER:** su listado es híbrido y su baja se modela como `DELETE` con restricción (no se borra una unidad referenciada por insumos). El endpoint de baja se documenta como `DELETE /unidades-medida/{id}` con bloqueo si está en uso; no hay `PATCH activar`.
- **Insumo `stock_actual` de solo lectura desde el CRUD** (D2): se expone en el Resource y el kardex, pero no es editable por `PUT /insumos/{id}`.
- **`activar` de insumos/proveedores** alterna el booleano `activo` (mismo patrón que el Sprint 4), no el soft delete.

## 5. Pruebas (DoD §2/§4)

- **Unit** (`tests/Unit/Inventario`): `RegistrarMovimientoServiceTest` (4 — entrada suma y append-only; `stock_resultante`; merma resta; **salida > stock se bloquea, D1, con rollback**); `ReconciliarStockServiceTest` (1 — recalcula desde el ledger); `GestionarRecetaServiceTest` (2 — crea línea; rechaza par duplicado).
- **Feature** (`tests/Feature/Inventario`): `ProveedorTest` (4), `UnidadMedidaTest` (4 — híbrido globales+propias; no edita global 403; crea/elimina propia; no elimina en uso 409), `InsumoTest` (5 — alta con stock 0; el CRUD no toca `stock_actual` D2; unidad inexistente 422; kardex; operador 403), `MovimientoTest` (6 — entrada admin; **operador registra merma pero no entrada/ajuste 403**; motivo obligatorio en ajuste 422; **merma > stock 422**; cantidad > 0), `RecetaTest` (4).
- **Integration** (`tests/Integration/Inventario`): `InventarioLedgerTest` (2 — **cache == suma firmada del ledger**; unidades híbridas + aislamiento de insumos entre tenants).
- **Estado:** suite completa **98/98 verde** en **SQLite** y en **PostgreSQL 17** (226 aserciones); **Pint** limpio. Sin migraciones nuevas ⇒ paridad de esquema sin cambios. (Sprint 4 cerró con 67; este sprint añade 31 pruebas.)

## 6. Fuera de alcance (sprints siguientes)

- **Descuento automático por venta** (M08 al cobrar) — **Sprint 8**.
- **Flujo de autorización del operador** para entrada/ajuste (M14) — **Sprint 9**.
- **Reversa de movimiento** (`RevertirMovimientoService`, salvaguarda venta) — **Sprint 8**.
- **Reportes de inventario / alertas de stock bajo** (consumo de `StockBajoDetectado`) — **Sprint 11**.

## 7. Deuda heredada (sin cambios)

Decisión formal de RLS, divergencia de timestamps (R4), unificación del patrón de auditoría (Observer en Sprint 12). Factories: este sprint añade las de inventario/recetas que sus pruebas requieren.
