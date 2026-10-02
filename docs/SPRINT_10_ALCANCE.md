# SPRINT_10_ALCANCE.md — Impresión (comanda, ticket y fallback PDF)

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Fecha:** 2026-06-29
**Base:** `RoadmapImplementacion.md` (Sprint 10, §4 Pruebas §530–532, §5 DoD), `EspecificacionFuncional.md` (M13, CU-17, regla global 10, P14/P15), `DatabaseDictionary.md` (`tickets`, `impresoras`, `configuracion_establecimiento`). Arranca sobre **Órdenes (S7, `ItemConfirmado`)**, **Pagos (S8, `OrdenPagada`)** y **Configuración (S2)**.

> **Contexto.** El Sprint 10 genera los documentos reales —**comanda** (lo que va a cocina/barra) y **ticket de cobro** (el comprobante de venta)— a partir de la configuración del establecimiento, los enruta a la impresora correspondiente y, si no hay impresora, los deja como **PDF** (P15). La impresión **nunca bloquea el cobro**: el envío físico se **encola** (cola `impresion`, con reintentos). La **reimpresión** no requiere autorización pero se **audita** (P14). Completa el paso 10 de §23.

---

## 0. Punto de partida (lo que ya existía de sprints previos)

- **Tablas `tickets`, `impresoras`** migradas desde S0:
  - `tickets`: `id_orden`, `id_usuario`, `id_impresora` (**NULL ⇒ PDF**, P15), `folio_ticket` (string 20, nullable), `contenido_json` JSONB, `tipo` ENUM(`comanda`,`cobro`), `impreso_at` (nullable).
  - `impresoras`: `nombre`, `tipo` ENUM(`ticket`,`barra`,`cocina`,`admin`), `conexion`, `activa`, soft delete. CRUD ya entregado en S4.
- **`configuracion_establecimiento`** (S2): `nombre_comercial`, `telefono_ticket`, `direccion_ticket`, **`impresion_automatica`** (bool, default `false`).
- **Eventos disponibles como puntos de integración:** `ItemConfirmado` (S7, al confirmar la comanda) y `OrdenPagada` (S8, al saldar). Ambos se emiten dentro de la transacción.
- **Permisos sembrados:** `tickets.imprimir` y `tickets.reimprimir` para **ADMIN y OPERADOR** (matriz Fase 7). **No se requiere cambio de seeder.**
- **Cola por defecto:** `database` (en pruebas, `sync`). La tabla `jobs` ya está migrada (Laravel 11).

---

## 1. Decisiones de producto ratificadas

| # | Decisión abierta | Resolución |
|---|---|---|
| D1 | **Fallback PDF (P15) sin dependencia pesada.** El roadmap usa dompdf para **reportes** (S11), no para tickets. | **No se instala dompdf en S10.** El fallback se modela con el contrato del propio DER: sin impresora, el ticket se persiste con `id_impresora = NULL`, queda **registrado y reimprimible**, y `GET /tickets/{id}` devuelve su `contenido_json` (vista previa estructurada) que el cliente renderiza/descarga como PDF. **Sin migraciones nuevas**: el esquema ya soporta `id_impresora` nullable. |
| D2 | **Selección de impresora por tipo de documento.** | La **comanda** se enruta a una impresora activa de tipo `cocina` (preferente) o `barra`; el **ticket de cobro** a una de tipo `ticket` (preferente) o `admin`. Si no hay ninguna activa del tipo apropiado → `id_impresora = NULL` (PDF, P15). |
| D3 | **Disparo automático vs. manual.** | `configuracion.impresion_automatica` gobierna el **auto-disparo**: los listeners `GenerarComanda` (sobre `ItemConfirmado`) e `ImprimirTicket` (sobre `OrdenPagada`) generan **solo si `impresion_automatica = true`** (auto-descubiertos por su `handle()`; **NO se registran en `AppServiceProvider`** —trampa de doble despacho del S8—). El endpoint manual `POST /ordenes/{id}/ticket` genera el ticket de cobro **on-demand siempre**. Con la bandera en `false` (default), la suite previa (S7/S8) **no se perturba**. |
| D4 | **Qué se audita.** | Solo la **reimpresión** se audita (`ticket.reimpreso`, P14: trazabilidad ante reimpresiones potencialmente abusivas). La generación primaria deja el ticket como **registro consultable** sin añadir ruido de auditoría. |
| D5 | **Estado requerido para el ticket de cobro.** | El ticket de cobro exige orden **`pagada`** (→ `TicketNoGenerableException` 422). La comanda exige al menos un renglón **enviado** (`enviado = true`). |

**Contratos ya fijados por el roadmap (se implementan, no se reabren):**
- **La impresión nunca bloquea el cobro:** persistir el ticket + auditar es síncrono; el **envío físico se encola** (cola `impresion`), con reintentos. Con el driver `database`, el job se inserta en la misma transacción y solo es visible al worker tras el commit (transaction-safe sin `afterCommit`).
- **Reimpresión a ambos roles sin autorización (P14)**, pero **auditada**.
- **Fallback PDF (P15):** sin impresora, el ticket queda registrado y reimprimible.

---

## 2. Módulos y endpoints (cadena `auth:sanctum → resolve.tenant → tenant.activo`)

| Acción | Endpoint | Permiso |
|---|---|---|
| Generar ticket de cobro (manual/on-demand) | `POST /api/v1/ordenes/{id}/ticket` | `tickets.imprimir` |
| Reimprimir (auditada) | `POST /api/v1/tickets/{id}/reimprimir` | `tickets.reimprimir` |
| Vista previa / payload PDF | `GET /api/v1/tickets/{id}` | `tickets.imprimir` |

La comanda se genera al **confirmar la comanda** (endpoint `POST /ordenes/{id}/comanda` ya existente en S7) vía el listener, no por un endpoint propio.

**Dominio** (`app/Domain/Impresion`):
- `TipoTicket` (enum `comanda`/`cobro`), `ContenidoTicket` (constructor puro del `contenido_json` desde config + orden), `SelectorImpresora` (elige impresora por tipo, D2).
- `GenerarComandaService` — construye la comanda (renglones enviados, **sin precios**), elige impresora (cocina/barra), persiste `tickets` tipo `comanda`, **encola** `EnviarComandaJob`.
- `GenerarTicketService` — exige orden `pagada` (D5); construye el ticket de cobro (renglones activos con precios, totales congelados y pagos), asigna `folio_ticket` (correlativo por tenant), elige impresora (ticket/admin), persiste `tickets` tipo `cobro`, **encola** `ImprimirTicketJob`.
- `ReimprimirTicketService` — re-encola el job del ticket existente, emite `TicketReimpreso`, **audita** `ticket.reimpreso` (P14).
- Evento `TicketReimpreso`.

**Jobs** (`app/Jobs`, cola `impresion`, `ShouldQueue`, `tries=3`): `EnviarComandaJob`, `ImprimirTicketJob` — efectúan el "envío" (marcan `impreso_at`; sin impresora, el documento es PDF). El driver físico real queda fuera del MVP (el job es el punto de extensión).

**Listeners** (`app/Listeners`, síncronos, auto-descubiertos): `GenerarComanda` (sobre `ItemConfirmado`), `ImprimirTicket` (sobre `OrdenPagada`). Ambos **gated** por `impresion_automatica` (D3).

**Policy:** `TicketPolicy` (`imprimir`/`reimprimir`/`view`; ambos roles, P14). **Resource:** `TicketResource`. **Excepción:** `TicketNoGenerableException` (422).

---

## 3. Contratos clave

- **Generar ticket de cobro.** `POST /ordenes/{id}/ticket`: orden `pagada` (→ 422 si no). `lockForUpdate` no es necesario (el ticket es derivado, no muta la orden). Construye `contenido_json` desde `configuracion` + orden + detalles + pagos; asigna `folio_ticket` (`T000001` continuo por tenant); elige impresora (D2); persiste; **encola** `ImprimirTicketJob->afterCommit()`. Devuelve `TicketResource`.
- **Comanda (automática).** Al confirmar comanda (S7), `ItemConfirmado` dispara `GenerarComanda` si `impresion_automatica`; construye la comanda con los renglones **enviados** (sin precios); persiste tipo `comanda`; **encola** `EnviarComandaJob`.
- **Reimpresión.** `POST /tickets/{id}/reimprimir`: re-encola el job del ticket existente (mismo `contenido_json`), emite `TicketReimpreso`, **audita** `ticket.reimpreso` **dentro de la transacción** (§15). Ambos roles (P14).
- **Fallback PDF (P15).** Si no hay impresora activa del tipo, el ticket se persiste con `id_impresora = NULL`; el job lo trata como PDF; `GET /tickets/{id}` devuelve el `contenido_json` para vista previa/descarga.
- **No bloquea el cobro.** El listener `ImprimirTicket` solo persiste el ticket (rápido) y **encola** el envío; el cobro nunca espera al I/O de impresión.
- **Aislamiento por tenant** (`TenantScope`): tickets, impresoras y configuración por establecimiento; ticket/orden de otro tenant → 404.

---

## 4. Pruebas (DoD §4 / §530–532)

- **Unit** (`tests/Unit/Impresion`):
  - `ContenidoTicketTest` — la comanda no lleva precios; el ticket de cobro incluye totales congelados y pagos; datos de cabecera desde la configuración.
  - `SelectorImpresoraTest` — comanda → cocina/barra; cobro → ticket/admin; sin impresora del tipo → null (PDF).
- **Feature** (`tests/Feature/Impresion`):
  - `TicketTest` — generar ticket de cobro (201); no generar sobre orden no pagada (422); reimpresión a ambos roles sin autorización pero auditada; `GET /tickets/{id}` (vista previa); sin impresora → PDF (`id_impresora` null) y reimprimible; aislamiento entre tenants.
- **Integration** (`tests/Integration/Impresion`):
  - `ImpresionFlujoTest` — la impresión **no bloquea el cobro**: con `impresion_automatica`, `OrdenPagada` persiste el ticket y **encola** el job (`Queue::fake`), sin afectar el cierre de la orden; la comanda automática se genera al confirmar.
- **Estado esperado:** suite **verde en SQLite y PostgreSQL 17**, **Pint limpio**, **sin migraciones nuevas** (paridad por construcción). Se parte de **188** (cierre del S9).

## 5. Fuera de alcance (sprints siguientes)

- **Driver físico real de impresión** (ESC/POS, IPP) y **binario PDF en servidor** (dompdf) — fuera del MVP; el job y el `contenido_json` son los puntos de extensión.
- **Reportes / exportación PDF-Excel** — Sprint 11.
- **Consolidación de auditoría (Observer) y endurecimiento** — Sprint 12.

## 6. Deuda heredada (sin cambios + nota)

Decisión formal de RLS, aritmética monetaria en float, unificación del patrón de auditoría (Observer en S12), riesgos del folio (S7, aplican también al `folio_ticket`). **Nota async-tenant:** los jobs se prueban con cola `sync`/`Queue::fake`; un worker asíncrono real necesitaría re-fijar el `TenantContext` (anotado como riesgo, sin impacto en MVP). Se aprovecha S10 para añadir asserts de eventos (`TicketReimpreso`, y los consumidores de `ItemConfirmado`/`OrdenPagada`).
