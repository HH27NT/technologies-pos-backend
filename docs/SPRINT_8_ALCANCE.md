# SPRINT_8_ALCANCE.md — Pagos, cierre de orden y descuento de inventario al cobrar

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Fecha:** 2026-06-19
**Base:** `RoadmapImplementacion.md` (Sprint 8, §4 Pruebas §519–522, §5 DoD), `EspecificacionFuncional.md` (M12, M08 descuento por venta, CU-12, reglas globales), `DatabaseDictionary.md` (`pagos`, `movimientos_inventario`, `tickets`). Arranca sobre **Órdenes (S7)**, **Caja (S6)**, **Inventario/Recetas (S5)**.

> **Contexto.** El Sprint 8 cierra el **camino crítico de la venta**: cobrar la orden (simple y dividido por monto), cerrarla al saldar, y disparar el **descuento de inventario al cobrar** (P1), la **liberación de mesa** (por derivación) y la integración de impresión (evento para S10). Es la continuación directa del ciclo de orden del S7: la orden `abierta` se totaliza, se cobra y pasa a `pagada`.

---

## 0. Punto de partida (lo que ya existía de sprints previos)

- **Tablas `pagos`, `tickets`, `movimientos_inventario`** migradas y verificadas desde el Sprint 0:
  - `pagos`: `id_orden`, `id_tipo_pago`, `id_usuario`, `monto` DECIMAL(12,2), `propina` (**reservada V2, sin uso** —P9—), `referencia` (string 100, nullable), `pagado_at`. Soporta **pago dividido** (N pagos por orden).
  - `movimientos_inventario`: ledger append-only; `tipo` incluye `venta`; `id_orden` nullable; `stock_resultante` **permite negativo** (P2).
  - `tickets`: `tipo` ENUM(`comanda`,`cobro`), `contenido_json` JSONB, `id_impresora` nullable (PDF si null, P15). **No se escribe en S8** (D2).
- **Modelos:** `Pago` (`BelongsToTenant`, `HasFactory`), `MovimientoInventario`, `Insumo` (`stock_actual` cache), `RecetaProducto` (BOM, `cantidad`), `Orden` (estados `abierta`/`pagada`/`anulada`, importes congelados), `Producto` (`controla_inventario`).
- **`TipoMovimiento` enum (S5):** ya tiene el case `Venta` (signo −1; `esManual()=false`: la venta NO es registrable a mano, la origina el cobro).
- **`SesionCaja` (S6):** el arqueo (`CerrarCajaService`) ya suma `pagos ⋈ ordenes` en efectivo; hoy da 0 porque `pagos` está vacío. **Al escribir pagos reales, el arqueo cobra valor sin reescritura.**
- **Permisos sembrados:** `ordenes.cobrar` (ADMIN y OPERADOR).
- **Eventos S7:** `OrdenCreada`, `ItemConfirmado`, `OrdenAnulada` ya emitidos; S8 añade `PagoRegistrado` y `OrdenPagada`.

---

## 1. Decisiones de producto ratificadas

| # | Decisión abierta | Resolución |
|---|---|---|
| D1 | **Idempotencia de pagos** (el roadmap pide "clave por intento" sin fijar el mecanismo). | **Respaldo en BD:** el cliente envía `referencia` (clave de idempotencia por intento); el servicio deduplica por `(id_orden, referencia)` dentro de la transacción y un **índice único parcial** `WHERE referencia IS NOT NULL` (pgsql) es la última salvaguarda. Un reintento con la misma `referencia` **devuelve el pago existente** sin recrear (no hay doble cobro). **Añade 1 migración portable** (la 2ª desde S0, análoga a la D1 del S6). |
| D2 | **Alcance del ticket de cobro en S8.** | **Solo se emite `OrdenPagada`.** La generación/impresión real del ticket (registro en `tickets`, `contenido_json`, PDF) es **Sprint 10** (M13), igual que `ItemConfirmado` quedó para la comanda. S8 deja el evento como punto de integración. |

**Contratos ya fijados por el roadmap (se implementan, no se reabren):**
- **Pago dividido por monto (P8):** N pagos parciales hasta saldar; al llegar el saldo a 0 la orden cierra `pagada`.
- **Sin propina (P9):** ningún proceso de V1 escribe `pagos.propina`.
- **Descuento de inventario AL COBRAR (P1):** disparado por `OrdenPagada`; recorre recetas; solo si el producto `controla_inventario` y tiene receta.
- **Stock negativo permitido (P2):** la venta **no se bloquea** por stock; `stock_resultante` puede ser negativo (a diferencia de la salida manual del S5, que sí se bloquea).
- **Reversa solo de `venta` (P3):** `RevertirMovimientoService` es salvaguarda y solo actúa sobre movimientos de origen `venta`, nunca sobre entrada/ajuste/merma.

---

## 2. Módulos y endpoints (cadena `auth:sanctum → resolve.tenant → tenant.activo`; `caja.abierta` donde se indica)

| Acción | Endpoint | Caja abierta |
|---|---|---|
| Registrar pago (simple/dividido) | `POST /api/v1/ordenes/{id}/pagos` | ✅ exige |
| Consultar saldo | `GET /api/v1/ordenes/{id}/saldo` | — |

**Servicios** (`app/Domain/Pagos/Services` e `Inventario/Services`, transaccionales y auditados §15):
- `RegistrarPagoService` — valida orden `abierta` y monto > 0; **idempotente por `referencia`** (D1); **pago dividido por monto** (P8); **sin propina** (P9); calcula `saldo = total − Σ pagos`; al llegar el saldo a 0 cierra la orden `pagada`, emite `OrdenPagada`; emite `PagoRegistrado`. Devuelve el **cambio** en efectivo (no se almacena).
- `DescontarInventarioService` — disparado por `OrdenPagada` (vía listener síncrono, **dentro de la transacción del cobro**, P1); recorre las recetas de los renglones **activos**; solo si el producto `controla_inventario` y tiene receta; descuenta `receta.cantidad × renglón.cantidad`; **permite negativo** (P2); emite `MovimientoRegistrado` y, si corresponde, `StockBajoDetectado`.
- `RevertirMovimientoService` — salvaguarda (P3): revierte **solo** los movimientos `venta` de una orden con un movimiento compensatorio. No se cablea a ningún flujo de usuario en S8 (cancelar/anular ocurren con la orden **abierta**, antes del cobro: no hay stock que revertir en el flujo normal).

**Listener:** `DescontarInventario` (síncrono, sobre `OrdenPagada`, registrado en `AppServiceProvider`).
**Policy:** `OrdenPolicy::cobrar` (`ordenes.cobrar`; ADMIN+OPERADOR). El saldo usa `view`.
**Form Request:** `RegistrarPagoRequest` (id_tipo_pago, monto, referencia).
**Resource:** `PagoResource`. El saldo se devuelve como payload estructurado (total/pagado/saldo/pagada).
**Eventos:** `PagoRegistrado`, `OrdenPagada` (consumidos por descuento de inventario, arqueo/reportes S11 e impresión S10).
**Excepciones de dominio:** `OrdenNoCobrableException` (422, orden no abierta o sin saldo), `SobrepagoNoPermitidoException` (422, monto > saldo en tarjeta/transferencia).

---

## 3. Contratos clave

- **Registrar pago.** Exige caja abierta (`EnsureCajaAbierta`) y orden `abierta` (→ `OrdenNoCobrableException` si pagada/anulada). `lockForUpdate` sobre la orden. **Idempotencia (D1):** si llega `referencia` y ya existe un pago `(id_orden, referencia)`, se devuelve ese pago **sin recrear** (mismo resultado, sin doble cobro). `monto > 0` (forma). El pago se asienta con `id_usuario = Auth::id()`, `pagado_at = now()`.
- **Saldo y cierre.** `saldo = round(orden.total − Σ pagos.monto, 2)`. Al asentar un pago cuyo aplicado lleva el saldo a `≤ 0`, la orden pasa a `pagada` con `cerrada_at`, se emite `OrdenPagada` y se audita `orden.pagada` **dentro de la transacción**.
- **Sobrepago (P-roadmap).** **Efectivo:** el `monto` recibido puede exceder el saldo; el pago **aplicado y almacenado** es `min(monto, saldo)` y la respuesta devuelve `cambio = monto − aplicado` (**el cambio no se almacena**; el arqueo suma solo lo aplicado, de modo que el efectivo neto en caja cuadra). **Tarjeta/Transferencia:** `monto > saldo` → `SobrepagoNoPermitidoException` (422).
- **Descuento de inventario al cobrar (P1/P2).** `OrdenPagada` dispara `DescontarInventarioService` **en la misma transacción** (listener síncrono): por cada renglón activo cuyo producto `controla_inventario` y tiene receta, se crea un movimiento `venta` por insumo con `cantidad = receta.cantidad × renglón.cantidad`, se actualiza `insumo.stock_actual` (cache) y `stock_resultante` (**puede ser negativo**, P2). Productos sin `controla_inventario` o sin receta **no generan movimiento** (contrato S4). `lockForUpdate` sobre cada insumo.
- **Liberación de mesa (regla global 11).** La mesa se libera **por derivación**: al pasar la orden a `pagada` deja de tener orden `abierta`, por lo que `mesa.ordenAbierta()` devuelve null. **No se persiste ni se modifica** ningún flag de mesa.
- **Atomicidad (§15).** Pago + cierre + descuento de inventario + auditoría financiera viven en **una sola transacción**. La impresión **no** se hace aquí (D2): se delega al evento (nunca bloquea el cobro).
- **Aislamiento por tenant** (`TenantScope`): pagos, saldo y movimientos por establecimiento; una orden de otro tenant → 404.

---

## 4. Decisiones de contrato menores

- **`referencia` opcional:** sin `referencia` el pago siempre se asienta (no hay deduplicación); con `referencia`, es idempotente. El front genera la clave por intento de cobro.
- **`monto` es el importe del tender:** en efectivo puede exceder el saldo (genera cambio); en no-efectivo no.
- **Sin reapertura:** una orden `pagada` es terminal; no se cobra de nuevo (→ 422).
- **`RevertirMovimientoService` sin cablear en S8:** se entrega como salvaguarda probada por unidad; su uso en flujo (anular post-cobro) no existe en el MVP porque anular ocurre pre-cobro.
- **Una migración nueva (D1):** índice único parcial en pgsql; en SQLite la idempotencia recae en la verificación del servicio (mismo criterio de salvaguarda que el índice de "una orden abierta por mesa" del S7).

## 5. Pruebas (DoD §4 / §519–522)

- **Unit** (`tests/Unit/Pagos`, `tests/Unit/Inventario`):
  - `RegistrarPagoServiceTest` — pago **dividido** por monto hasta saldar (P8); cierre al saldo 0; **cambio** en sobrepago efectivo; **idempotencia** por `referencia` (segundo intento no recrea ni recobra); **sin propina** (P9).
  - `RevertirMovimientoServiceTest` — revierte **solo** movimientos `venta` (P3); ignora entrada/ajuste/merma.
- **Feature** (`tests/Feature/Pagos`):
  - `PagoTest` — cobro **simple** y **dividido**; cierre al saldo cero (`pagada`); **no cobrar orden ya pagada → 422**; **sobrepago en tarjeta → 422**; cambio en efectivo; saldo (`GET .../saldo`).
- **Integration** (`tests/Integration/Pagos`):
  - `CobroInventarioTest` — `OrdenPagada` dispara el descuento (**recorre recetas**; permite negativo, P2); productos sin receta no generan movimiento; **libera la mesa por derivación**; **auditoría financiera** (`orden.pagada`) dentro de la transacción; aislamiento entre tenants.
- **Estado esperado:** suite **verde en SQLite y PostgreSQL 17**, **Pint limpio**, migración con ciclo `up/down/fresh` verificado en pgsql. Se parte de **158** (cierre del S7).

## 6. Fuera de alcance (sprints siguientes)

- **Generación/impresión del ticket de cobro** (registro en `tickets`, `contenido_json`, PDF fallback) — **Sprint 10**.
- **Flujo de autorización de dos niveles** (cancelar/anular por operador) — **Sprint 9**.
- **Reporte de medios de pago, cancelaciones, margen** — **Sprint 11**.
- **Propina** — reservada V2.

## 7. Deuda heredada (sin cambios + nota)

Decisión formal de RLS, aritmética monetaria en float (criterio común S5/S6/S7), unificación del patrón de auditoría (Observer en S12). **Nota:** los riesgos del folio (S7 R1/R2) siguen abiertos como deuda baja. Factories: las de `Pago`/`Orden`/`DetalleOrden` ya existen; este sprint no añade factories nuevas.
