# SPRINT_10_REVIEW.md — Revisión Arquitectónica del Sprint 10

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Revisor:** Arquitecto de Software Senior
**Fecha:** 2026-06-29
**Base de evaluación:** `RoadmapImplementacion.md` (Sprint 10, §4 Pruebas §530–532, §5 DoD), `SPRINT_10_ALCANCE.md`, `EspecificacionFuncional.md` (M13, CU-17, P14/P15, regla global 10), `ArquitecturaBackend.md` (§9–§10, §15), `Convenciones.md`, `DatabaseDictionary.md` (`tickets`, `impresoras`).
**Objeto:** validar el **Sprint 10 — Impresión (comanda, ticket y fallback PDF, M13)**: generación de comanda y ticket de cobro desde la configuración, enrutamiento por impresora, fallback PDF, y reimpresión auditada sin bloquear el cobro.

---

## 0. Encuadre de alcance (qué exigía el Sprint 10 y qué se entregó)

El Roadmap define el Sprint 10 como la salida del núcleo: generar comandas y tickets reales, enrutarlos y exportar a PDF cuando no hay impresora (paso 10 de §23). El alcance entregado coincide y ratifica las cinco decisiones que la documentación dejaba abiertas (`SPRINT_10_ALCANCE.md` §1):

- **D1** — fallback PDF **sin dependencia nueva**: sin impresora, el ticket se persiste con `id_impresora = NULL`, queda reimprimible, y `GET /tickets/{id}` devuelve el `contenido_json` para vista previa/descarga.
- **D2** — selección de impresora por tipo (comanda → cocina/barra; cobro → ticket/admin; sin candidata → PDF).
- **D3** — `impresion_automatica` gobierna el auto-disparo (listeners gated); el endpoint manual genera on-demand.
- **D4** — solo la **reimpresión** se audita (`ticket.reimpreso`, P14).
- **D5** — el ticket de cobro exige orden `pagada`; la comanda, renglones enviados.

**Hallazgo de encuadre.** El sprint vuelve a la dinámica de **capa de aplicación pura**: **sin migraciones nuevas** (el esquema `tickets`/`impresoras` ya estaba en S0, incluido `id_impresora` nullable para el PDF). No se detecta *scope creep*: no se instaló dompdf (es de reportes S11), no se adelantaron los reportes ni la consolidación de auditoría. La impresión se mantiene **encolada** para no bloquear el cobro, tal como el S8 lo dejó encaminado.

---

## 1. Verificación punto por punto

Leyenda: ✅ Correcto · ⚠️ Correcto con observación/riesgo · ⛔ Defecto/Pendiente · ⏭️ Diferido por diseño

| # | Área | Estado | Evidencia |
|---|------|--------|-----------|
| 1 | **Generar ticket de cobro** | ✅ | `GenerarTicketService`: exige `pagada`, arma `contenido_json` desde config+orden+pagos, asigna `folio_ticket` continuo, persiste, encola. Feature: 201, `tipo=cobro`, `folio_ticket=T000001`. |
| 2 | **Comanda sin precios** | ✅ | `ContenidoTicket::comanda` solo renglones enviados con cantidad+producto. Unit: el ítem **no** lleva `precio_unitario`. |
| 3 | **Ticket de cobro con totales y pagos** | ✅ | `ContenidoTicket::cobro`: totales congelados + pagos + precios. Unit: `total=100`, 1 pago de 100. |
| 4 | **Selección por tipo (D2)** | ✅ | `SelectorImpresora`: comanda prefiere cocina→barra; cobro prefiere ticket→admin; preferencia resuelta en PHP (paridad). Unit cubre los 4 caminos + inactivas. |
| 5 | **Fallback PDF (P15)** | ✅ | Sin impresora del tipo → `id_impresora=NULL`; `es_pdf=true`; reimprimible. Feature lo asserta. |
| 6 | **Vista previa** | ✅ | `GET /tickets/{id}` devuelve `contenido_json`. Feature: `data.contenido_json.totales.total=100`. |
| 7 | **Reimpresión auditada (P14)** | ✅ | `ReimprimirTicketService` re-encola el job y audita `ticket.reimpreso` en la transacción. Feature: `assertDatabaseHas('auditoria', ...)`. |
| 8 | **Reimpresión a ambos roles** | ✅ | `TicketPolicy::reimprimir` → `tickets.reimprimir` (ADMIN+OPERADOR, ya sembrado). Feature: operador reimprime → 200. |
| 9 | **No genera ticket de orden no pagada (D5)** | ✅ | `TicketNoGenerableException` (422). Feature: orden abierta → 422. |
| 10 | **Auto-disparo gated (D3)** | ✅ | Listeners `GenerarComanda`/`ImprimirTicket` generan solo si `impresion_automatica`. Integración: con bandera, ticket persistido + job encolado; sin bandera, nada. |
| 11 | **No bloquea el cobro** | ✅ | El listener persiste el ticket (rápido) y encola (`Queue::fake` asserta `ImprimirTicketJob` pushed); la orden cierra `pagada` igual. |
| 12 | **Jobs en cola `impresion` con reintentos** | ✅ | `EnviarComandaJob`/`ImprimirTicketJob` (`ShouldQueue`, `onQueue('impresion')`, `tries=3`, `backoff=5`). |
| 13 | **Auto-discovery sin doble registro** | ✅ | Listeners con `handle()` en `app/Listeners`; **no** registrados en `AppServiceProvider` (se respeta la convención del S8). |
| 14 | **Aislamiento multi-tenant** | ✅ | `TenantScope`; ticket de otro tenant → 404. Feature lo asserta. |
| 15 | **Sin migraciones / paridad** | ✅ | Capa de aplicación pura; suite verde en SQLite y PostgreSQL 17 sin tocar el esquema. |
| 16 | **Despacho del job transaction-safe** | ⚠️ | Sin `afterCommit`: con el driver `database` el job se inserta en la transacción y solo es visible tras el commit. Correcto para este driver; con Redis/SQS habría que reintroducir `afterCommit` — ver R1. |

**Pruebas ejecutadas en esta revisión:** `php artisan test` → **205 passed (504 assertions)** en SQLite; `php artisan test -c phpunit.pgsql.xml` → **205 passed** en **PostgreSQL 17**; `pint --test` → `passed`. El Sprint 10 añade **17 pruebas** (7 Unit, 7 Feature, 3 Integration) sobre las 188 del cierre del S9.

---

## 2. Qué quedó correcto

1. **La impresión no bloquea el cobro, comprobado.** El listener `ImprimirTicket` persiste el ticket y encola el envío; `Queue::fake` confirma que el job se despacha sin que el cobro espere al I/O. La orden cierra `pagada` en la misma transacción que antes.
2. **Fallback PDF fiel a P15 sin deuda de dependencias.** No se instaló dompdf: el contrato del DER (`id_impresora` nullable) basta para registrar el documento, marcarlo PDF y dejarlo reimprimible; el `contenido_json` es el payload que el cliente renderiza.
3. **El contenido se construye desde una calculadora pura.** `ContenidoTicket` no tiene estado ni efectos: la comanda omite precios y el ticket de cobro congela totales y pagos, cada caso verificado por unidad.
4. **Auto-disparo gobernado por configuración, sin perturbar lo previo.** Con `impresion_automatica=false` (default) los listeners son no-op, de modo que la suite de S7/S8 quedó intacta; con la bandera, comanda y ticket se generan y encolan.
5. **Disciplina ante el auto-discovery.** Los nuevos listeners se dejaron auto-descubiertos y **no** se registraron en el provider, respetando la lección del doble descuento del S8.
6. **Reimpresión trazada.** P14 se implementa tal cual: ambos roles, sin autorización, pero con `ticket.reimpreso` auditado dentro de la transacción.

---

## 3. Qué quedó incompleto

### Diferido por diseño (no bloquea el cierre del Sprint 10)
- **Driver físico real** (ESC/POS, IPP) y **binario PDF en servidor** (dompdf) — fuera del MVP; el job y el `contenido_json` son los puntos de extensión.
- **Reportes / exportación** — Sprint 11.
- **Consolidación de auditoría (Observer)** — Sprint 12.

### Observaciones a gestionar (no bloqueantes)
- **Despacho atado al driver `database`** (R1).
- **Contexto de tenant en un worker asíncrono real** (R2).
- **`folio_ticket` hereda las propiedades del trait `GeneraFolio`** (R3).

---

## 4. Riesgos

| ID | Riesgo | Severidad | Impacto |
|----|--------|-----------|---------|
| R1 | **El despacho sin `afterCommit` depende del driver `database`.** Con ese driver el job se inserta en la transacción y se hace visible tras el commit; con Redis/SQS el job podría ejecutarse antes del commit y no ver el ticket. | Baja | El proyecto usa `database` (config y tests con `sync`). Si se migra a Redis/SQS, reintroducir `->afterCommit()` (y ajustar las pruebas, que bajo `RefreshDatabase` no ejecutan callbacks de commit). Anotado en el ALCANCE. |
| R2 | **Contexto de tenant en worker asíncrono.** Los jobs operan con `withoutGlobalScopes()->find()` y actualizan por PK, lo que evita el problema; pero un job que reconstruyera datos por scope necesitaría re-fijar el `TenantContext`. | Baja | Hoy no aplica (los jobs solo marcan `impreso_at` por PK). Anotado para cuando el driver físico real lea configuración/relaciones dentro del job. |
| R3 | **`folio_ticket` usa `proximoCorrelativo` (max + 1) sin bloqueo.** Igual que el folio de orden (S7), es lexicográfico con padding y no serializa con `lockForUpdate`. | Baja | Para el volumen de un bar es irrelevante; dos tickets concurrentes podrían, en teoría, calcular el mismo correlativo. El `folio_ticket` no es clave única en el esquema (a diferencia del folio de orden). Heredado del trait S0. |
| R4 | **`impreso_at` se fija en el job, no al generar.** Con `Queue::fake` queda null; en `sync`/producción lo fija el worker. | Baja | Refleja el momento del envío real, no de la generación; coherente con "encolado". El `TicketResource` devuelto tras generar muestra `impreso_at=null` (aún no enviado), lo cual es correcto. |
| R5 | **Aritmética monetaria en float** en el `contenido_json` (totales/pagos). | Baja | Solo presentación (los importes ya están congelados en la orden); mismo criterio común S5–S8. |

---

## 5. Qué debe corregirse antes del Sprint 11

**Bloqueantes:** ninguno. El Sprint 10 cumple su DoD —generación de comanda y ticket con `contenido_json`, enrutamiento por tipo de impresora, fallback PDF, reimpresión auditada, jobs encolados con reintentos, suite verde en SQLite y PostgreSQL, sin migraciones (paridad por construcción), aislamiento probado, policy, auditoría transaccional— sin defectos abiertos ni P0.

**Recomendados (cerrar para no arrastrar deuda):**
1. **Reintroducir `afterCommit`** si se migra el driver de cola a Redis/SQS (R1).
2. **Re-fijar el `TenantContext`** en los jobs cuando el driver físico real lea relaciones/configuración (R2).

**Heredados (deuda planificada):** decisión formal de RLS, aritmética monetaria en float (R5), unificación del patrón de auditoría (Observer en S12), propiedades del folio (R3, común con S7).

---

## 6. Conclusión

El **Sprint 10 está bien implementado, es idiomático y está en verde** en ambos motores (205 tests, 504 aserciones, Pint limpio). Lo esencial —la generación de comanda (sin precios) y ticket de cobro (con totales y pagos) desde una calculadora pura, el enrutamiento por tipo de impresora con fallback PDF fiel a P15, la reimpresión a ambos roles auditada (P14), y el auto-disparo gobernado por `impresion_automatica` que **nunca bloquea el cobro**— está construido y probado. No hay migraciones, de modo que la paridad de esquema se conserva por construcción, y los nuevos listeners respetan la convención de auto-discovery que el S8 dejó como lección.

Las observaciones son honestas y **no bloqueantes**: el despacho transaction-safe atado al driver `database`, el contexto de tenant en un worker real, y las propiedades heredadas del folio. Ninguna compromete el inicio del Sprint 11.

---

# Dictamen

## APROBADO PARA SPRINT 11

**Motivo:** la capa de Impresión está entregada conforme al roadmap y al DoD, verificada en SQLite y PostgreSQL, sin bloqueantes ni P0. El Sprint 11 (Reportes y Dashboard, M16) tiene sus dependencias satisfechas: el núcleo transaccional completo (órdenes, pagos, inventario), la caja con arqueo, las autorizaciones y ahora los tickets como fuentes de datos de solo lectura.

**Recomendación de arranque:** construir los Query Services de solo lectura (dashboard, ventas, inventario, caja, medios de pago, cancelaciones, margen) respetando el alcance por rol (P21: operador solo su turno) y la zona horaria del establecimiento; resolver el reporte de margen con la fuente de costo configurable (P20); y aquí sí evaluar dompdf/Laravel Excel para la exportación, ya como dependencia justificada de reportes.
