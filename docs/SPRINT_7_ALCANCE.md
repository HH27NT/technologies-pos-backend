# SPRINT_7_ALCANCE.md — Órdenes y totalizador

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Fecha:** 2026-06-18
**Base:** `RoadmapImplementacion.md` (Sprint 7, §4 Pruebas §514–517, §5 DoD), `EspecificacionFuncional.md` (M11, CU-07/08/09/10/11, F1/F2/F4/F5, matriz Fase 7, reglas globales 9–13), `DatabaseDictionary.md` (`ordenes`, `detalle_orden`). Arranca sobre **Caja (S6)**, **Catálogo (S4: mesas/productos)** e **Inventario (S5)**.

> **Contexto.** El Sprint 7 construye el **ciclo de vida de la orden** (M11) hasta **antes del cobro**: crear (mesa o barra), agregar/modificar ítems, totalizar con impuesto configurable y descuento del cajero, enviar comanda, y cancelar ítem / anular orden de forma **directa por ADMIN**. Es la continuación del núcleo transaccional: la compuerta de caja (S6) ya existe; el cobro, el descuento de inventario y el ticket llegan en S8.

---

## 0. Punto de partida (lo que ya existía del Sprint 0 y sprints previos)

- **Tablas `ordenes` y `detalle_orden`** migradas y verificadas en PostgreSQL desde el Sprint 0:
  - `ordenes`: `folio` con UNIQUE `(id_establecimiento, folio)`; **índice único parcial** `uq_ordenes_abierta_mesa_parcial ON (id_mesa) WHERE estado='abierta' AND id_mesa IS NOT NULL` (solo PostgreSQL); `estado` ENUM(`abierta`,`pagada`,`anulada`); importes congelados (`descuento`/`subtotal`/`impuesto`/`total`); `abierta_at`/`cerrada_at`.
  - `detalle_orden`: `enviado` (BOOL, default false), `estado_item` ENUM(`activo`,`cancelado`), `cancelado_at`, `id_autorizacion` (nullable, para S9), `precio_unitario`/`descuento_item`/`subtotal`; `id_orden` ON DELETE CASCADE.
- **Modelos:** `Orden` (`BelongsToTenant`, `GeneraFolio`, `HasFactory`), `DetalleOrden` (`BelongsToTenant`), `Producto` (`precio_venta`, `disponible`, `controla_inventario`), `ConfiguracionEstablecimiento` (`aplica_impuesto`, `tasa_impuesto` DECIMAL(5,2) como **porcentaje** 0–100), `SesionCaja` (S6), `Mesa` (S4).
- **Trait `GeneraFolio`** listo (`proximoCorrelativo($columna, $tenant)`, `withoutGlobalScopes`); su uso seguro ante concurrencia es responsabilidad del servicio (P12).
- **Middleware `EnsureCajaAbierta`** (alias `caja.abierta`, S6) listo para colgarse de estas rutas — cierra la recomendación de arranque del review del S6.
- **Permisos sembrados:** `ordenes.crear`, `ordenes.agregar_item`, `ordenes.aplicar_descuento`, `ordenes.cobrar` (ADMIN y OPERADOR); `ordenes.cancelar_item`, `ordenes.anular` (**solo ADMIN**).

**Sin migraciones nuevas:** el Sprint 7 vuelve a la dinámica de capa de aplicación (la columna `motivo` de S6 fue la excepción). La paridad SQLite↔PostgreSQL se conserva por construcción.

---

## 1. Decisiones de producto ratificadas

| # | Decisión abierta | Resolución |
|---|---|---|
| D1 | **Base imponible del impuesto cuando hay descuento de orden** (P11 dice "subtotal − descuento + impuesto" sin fijar la base). | **Impuesto sobre el neto:** `impuesto = round(tasa/100 × (subtotal − descuento), 2)`; `total = (subtotal − descuento) + impuesto`. El descuento reduce la base gravable. |

**Contratos ya fijados por el roadmap (se implementan, no se reabren):**
- **Inventario NO se toca en S7 (P1):** ni al agregar ítem ni al confirmar comanda. El descuento de inventario se dispara **al cobrar** (S8).
- **Cancelar ítem / anular orden: solo ADMIN directo** en S7 (permiso directo). El flujo de dos niveles (operador solicita → admin aprueba, con `id_autorizacion`) es **S9**.
- **Descuento del cajero sin autorización (P10):** ADMIN y OPERADOR aplican descuento de orden sin aprobación.
- **Impuesto sumado aparte (P11):** `precio_venta` **no** incluye impuesto; el impuesto se calcula y se suma como renglón del total.

---

## 2. Módulos y endpoints (cadena `auth:sanctum → resolve.tenant → tenant.activo`; `caja.abierta` donde se indica)

| Acción | Endpoint | Caja abierta |
|---|---|---|
| Crear orden (mesa/barra) | `POST /api/v1/ordenes` | ✅ exige |
| Listar / ver orden | `GET /api/v1/ordenes`, `GET /api/v1/ordenes/{id}` | — |
| Agregar ítem | `POST /api/v1/ordenes/{id}/items` | ✅ exige |
| Modificar cantidad de ítem (no enviado) | `PUT /api/v1/ordenes/{id}/items/{itemId}` | ✅ exige |
| Confirmar comanda | `POST /api/v1/ordenes/{id}/comanda` | ✅ exige |
| Aplicar descuento de orden (cajero) | `POST /api/v1/ordenes/{id}/descuento` | ✅ exige |
| Cancelar ítem (ADMIN) | `PATCH /api/v1/ordenes/{id}/items/{itemId}/cancelar` | — |
| Anular orden (ADMIN) | `PATCH /api/v1/ordenes/{id}/anular` | — |

**Servicios** (`app/Domain/Ordenes/Services`, transaccionales y auditados §15): `CrearOrdenService` (exige caja abierta; `lockForUpdate` sobre la mesa; valida "una orden abierta por mesa"; folio continuo por tenant con `GeneraFolio` dentro de la transacción; emite `OrdenCreada`), `AgregarItemService` (alta de renglón + recálculo), `ModificarItemService` (solo `enviado=false`), `ConfirmarComandaService` (marca `enviado=true`; **no toca inventario**; emite `ItemConfirmado`), `AplicarDescuentoService` (descuento de orden, cajero, sin autorización), `CancelarItemService` (ADMIN directo), `AnularOrdenService` (ADMIN directo; emite `OrdenAnulada`). `TotalizadorOrden` es una **calculadora pura** (sin estado/transacción) consumida por los servicios.

**Policy:** `OrdenPolicy` — `crear`/`agregarItem`/`aplicarDescuento` (ADMIN+OPERADOR), `cancelarItem`/`anular` (solo ADMIN), `viewAny`/`view`.
**Form Requests:** `CrearOrdenRequest`, `GuardarItemRequest`, `ModificarItemRequest`, `AplicarDescuentoRequest`, `AnularOrdenRequest`/`CancelarItemRequest` (motivo).
**Resources:** `OrdenResource`, `DetalleOrdenResource`.
**Eventos:** `OrdenCreada`, `ItemConfirmado`, `OrdenAnulada` (consumidos por auditoría/impresión/reportes en S8/S10/S11).
**Excepciones de dominio:** `MesaOcupadaException` (409), `OrdenNoModificableException` (422, orden no abierta o ítem enviado), `ProductoNoDisponibleException` (422), `DescuentoInvalidoException` (422, descuento > base).

---

## 3. Contratos clave

- **Crear orden.** Exige caja abierta (`EnsureCajaAbierta`). Tipo **mesa**: `id_mesa` obligatorio, mesa **activa** y **del tenant**; `lockForUpdate` sobre la mesa + verificación "una orden abierta por mesa" (respaldo: índice único parcial en pgsql). Tipo **barra/llevar**: `id_mesa` nulo, sin restricción de mesa. **Folio continuo por tenant** vía `GeneraFolio` dentro de la transacción (P12: no reinicia, no duplica). Estado `abierta`; emite `OrdenCreada`; audita `orden.creada`.
- **Agregar ítem.** Solo sobre orden `abierta`; producto **disponible** y del tenant; `precio_unitario` se **congela** = `producto.precio_venta`; `cantidad > 0`; `subtotal` del renglón = `cantidad × precio_unitario − descuento_item`. Recalcula los totales. **No toca inventario** (P1).
- **Modificar cantidad.** Solo si el renglón tiene `enviado=false`; recalcula. Un ítem ya enviado a comanda no cambia de cantidad (→ 422).
- **Confirmar comanda.** Marca los renglones `activo` con `enviado=false` → `enviado=true`; **no toca inventario**; emite `ItemConfirmado`. Comanda **incremental**: los ítems agregados después se envían en una comanda posterior. (La impresión física es S10; aquí se marca el estado y se emite el evento.)
- **Descuento de orden.** Monto fijo (la columna `descuento` es importe), a nivel orden; cajero **sin autorización** (P10); no puede exceder el `subtotal` (→ `DescuentoInvalidoException`). Recalcula.
- **Totalizador (P11 / D1).** `subtotal = Σ subtotales de renglones activos`; `base = subtotal − descuento`; `impuesto = aplica_impuesto ? round(tasa_impuesto/100 × base, 2) : 0`; `total = base + impuesto`. `precio_venta` sin impuesto. Renglones `cancelado` no cuentan.
- **No modificar órdenes cerradas.** Solo `estado=abierta` acepta ítems/descuento/comanda; orden `pagada` o `anulada` → 422.
- **Cancelar ítem (ADMIN directo, S7).** Marca `estado_item=cancelado`, `cancelado_at`; recalcula totales; audita `orden.item_cancelado`. La reversa de inventario **no aplica** en S7 (nada se descontó aún, P1). El operador (🔐) y el enlace `id_autorizacion` llegan en S9.
- **Anular orden (ADMIN directo, S7).** `estado=anulada`, `cerrada_at`; la mesa se libera por **derivación** (regla global 11: el estado de mesa se deriva de la orden abierta, no se persiste); audita `orden.anulada` (pérdida registrada / walkout). Reversa de inventario no aplica pre-cobro. El flujo del operador llega en S9.
- **Auditoría §15:** cada acción se registra dentro de su transacción (patrón S4–S6).
- **Aislamiento por tenant** (`TenantScope`): folio y "una orden abierta por mesa" son por establecimiento; una orden de otro tenant → 404.

---

## 4. Decisiones de contrato menores

- **Formato de folio:** correlativo continuo por tenant (entero vía `GeneraFolio`), persistido como string con relleno de ceros (p. ej. `000001`); sin prefijo. `GeneraFolio` extrae la parte numérica, de modo que el formato es estable y comparable.
- **`descuento_item` (renglón) queda en 0 en S7:** el descuento es a nivel orden (P10); el campo de renglón se reserva para una futura división por ítems.
- **Ocupación de mesa derivada (regla 11):** S7 **no** persiste ni expone un flag `ocupada`; el cliente la deriva de las órdenes `abiertas`. (Diferido; no toca el módulo de Mesas del S4.)
- **`tasa_impuesto` como porcentaje** (0–100; ya validado en M03); el totalizador divide entre 100.
- **Sin migraciones nuevas** ⇒ paridad de esquema sin cambios.

## 5. Pruebas (DoD §4 / §514–517)

- **Unit** (`tests/Unit/Ordenes`):
  - `TotalizadorOrdenTest` — impuesto sobre el **neto** (`subtotal − descuento`, P11/D1); **sin** impuesto cuando `aplica_impuesto=false`; redondeo a 2 decimales.
  - `GeneraFolioTest` — correlativo **continuo** por tenant (P12: no reinicia, no duplica); **aislado** entre tenants.
- **Feature** (`tests/Feature/Ordenes`):
  - `OrdenTest` — crear orden **mesa** (201, folio, estado abierta); crear orden **barra** (sin mesa); **crear sin caja → 409** (`EnsureCajaAbierta`); **mesa ocupada → 409** (segunda orden en la misma mesa); listar/ver.
  - `ItemTest` — agregar ítem (recalcula totales; precio congelado); modificar cantidad de ítem **no enviado**; **no modificar ítem enviado → 422**; **no agregar a orden pagada/anulada → 422**; producto no disponible → 422.
  - `ComandaTest` — confirmar comanda marca `enviado=true` y **no crea movimientos de inventario** (P1, assert sobre `movimientos_inventario`).
  - `DescuentoTest` — descuento del cajero (OPERADOR) **sin autorización** (P10); descuento > subtotal → 422.
  - `CancelacionTest` — cancelar ítem y anular orden por **ADMIN**; **OPERADOR → 403** en ambos (el flujo de autorización es S9).
- **Integration / Concurrencia** (`tests/Integration/Ordenes`):
  - `OrdenConcurrenciaTest` — **dobles órdenes en una mesa fallan** (índice único parcial + bloqueo); **folio continuo** seguro; **auditoría** de `orden.creada`/`orden.anulada`; aislamiento entre tenants.
- **Estado esperado:** suite **verde en SQLite y PostgreSQL 17**, **Pint limpio**. Se parte de **126** (cierre del S6).

## 6. Fuera de alcance (sprints siguientes)

- **Cobro / cierre de orden / descuento de inventario al cobrar / liberación de mesa al pagar / ticket** — **Sprint 8**.
- **Flujo de autorización de dos niveles** (operador solicita → admin aprueba) para cancelar ítem / anular orden — **Sprint 9** (incluye el enlace `id_autorizacion`).
- **Impresión física de la comanda** a la impresora del tipo correspondiente — **Sprint 10** (en S7 se marca `enviado` y se emite `ItemConfirmado`).
- **Exposición de la ocupación de mesa** derivada — diferida (el cliente la deriva de las órdenes abiertas).
- **Descuento por ítem y división por ítems** — V2/posterior.

## 7. Deuda heredada (sin cambios + nota)

Decisión formal de RLS, divergencia de timestamps (R4), unificación del patrón de auditoría (Observer en S12). **Nota:** los estados de `ordenes` (`abierta`/`pagada`/`anulada`) y `detalle_orden` (`activo`/`cancelado`) conviene centralizarlos en enums (mismo criterio que `TipoMovimiento` del S5 y la recomendación de `EstadoCaja` del review del S6). Factories: este sprint añade la de `DetalleOrden` (las de `Orden`/`Pago` ya se crearon en S6).
