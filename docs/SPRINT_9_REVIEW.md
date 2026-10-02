# SPRINT_9_REVIEW.md — Revisión Arquitectónica del Sprint 9

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Revisor:** Arquitecto de Software Senior
**Fecha:** 2026-06-29
**Base de evaluación:** `RoadmapImplementacion.md` (Sprint 9, §4 Pruebas §524–527, §5 DoD), `SPRINT_9_ALCANCE.md`, `EspecificacionFuncional.md` (M14, CU-08/CU-13/CU-16, matriz Fase 7, reglas globales 21–23), `ArquitecturaBackend.md` (§8–§10, §15), `Convenciones.md`, `DatabaseDictionary.md` (`autorizaciones`).
**Objeto:** validar el **Sprint 9 — Autorizaciones (flujo de dos niveles, M14)**: "operador solicita → admin autoriza → sistema ejecuta el servicio destino → sistema audita" para cancelar ítem, anular orden, entrada y ajuste de stock.

---

## 0. Encuadre de alcance (qué exigía el Sprint 9 y qué se entregó)

El Roadmap define el Sprint 9 como la materialización del flujo de dos niveles sobre el núcleo transaccional ya estable (paso 8 de §23). El alcance entregado coincide y ratifica las tres decisiones que la documentación dejaba abiertas (`SPRINT_9_ALCANCE.md` §1):

- **D1** — payload de la operación pendiente en una **columna `datos` JSONB** nueva en `autorizaciones` (una entrada/ajuste necesita `id_insumo`/`cantidad`/`costo_unitario` que no existen como movimiento mientras está `pendiente`).
- **D2** — nuevo permiso **`autorizaciones.solicitar`** para el OPERADOR; el ADMIN resuelve con `autorizaciones.aprobar` (ya sembrado).
- **D3** — `motivo` **obligatorio** en toda solicitud (justificación para la bandeja del admin).

**Hallazgo de encuadre.** El sprint añade **una migración** —la tercera desde el S0, análoga a `motivo` (S6) y al índice de idempotencia (S8)—, justificada por una carencia real del esquema (no había dónde guardar los parámetros de la operación pendiente); es una columna nullable, no altera la forma del resto de la tabla. No se detecta *scope creep*: no se adelantó la impresión/reimpresión (S10), ni los reportes de autorizaciones (S11). Se **reutilizan** los servicios destino de S7/S5 sin reescribirlos.

---

## 1. Verificación punto por punto

Leyenda: ✅ Correcto · ⚠️ Correcto con observación/riesgo · ⛔ Defecto/Pendiente · ⏭️ Diferido por diseño

| # | Área | Estado | Evidencia |
|---|------|--------|-----------|
| 1 | **Operador solicita (201)** | ✅ | `SolicitarAutorizacionService` crea `pendiente` con entidad/entidad_id/motivo/datos; emite `AutorizacionSolicitada`; audita `autorizacion.solicitada`. Feature: 201 + `estado=pendiente`. |
| 2 | **Admin aprueba → ejecuta destino** | ✅ | `ResolverAutorizacionService::aprobar` invoca `CancelarItemService`/`AnularOrdenService`/`RegistrarMovimientoService` con `id_autorizacion`. Unit: ítem cancelado / movimiento creado. |
| 3 | **Enlace de la operación ejecutada** | ✅ | `detalle_orden.id_autorizacion` y `movimientos_inventario.id_autorizacion` quedan fijados. Integración asserta ambos enlaces. |
| 4 | **Admin rechaza sin efectos** | ✅ | `rechazar` marca `rechazada` y NO ejecuta el destino; audita `autorizacion.rechazada`. Feature: el ítem permanece activo. |
| 5 | **Idempotencia de resolución (regla 22)** | ✅ | `lockForUpdate` + verificación `esResuelta()` → `AutorizacionYaResueltaException` (422). Unit (rechazar lo aprobado) y Feature (re-aprobar → 422). |
| 6 | **Operador no aprueba (403)** | ✅ | `AutorizacionPolicy::aprobar` → `autorizaciones.aprobar` (solo admin). Feature: operador → 403. |
| 7 | **Operador sin permiso directo (🔐)** | ✅ | El seeder NO da al operador `ordenes.cancelar_item`/`anular`/`inventario.entrada`/`ajustar`. Feature: las 4 rutas directas → 403 (con insumo válido para que el bloqueo sea de autz, no de forma). |
| 8 | **Bandeja del admin** | ✅ | `GET /autorizaciones` (filtrable por estado/tipo) bajo `viewAny`; operador → 403. Feature verifica el listado de pendientes. |
| 9 | **Atomicidad y auditoría §15** | ✅ | Solicitud y resolución en `DB::transaction`; el destino abre su propio savepoint (patrón S8). Auditoría dentro de la transacción. |
| 10 | **Aislamiento multi-tenant** | ✅ | `TenantScope`/`BelongsToTenant`; autorización de otro tenant → 404 (el admin de B no resuelve la de A; sigue `pendiente`). |
| 11 | **Ejecución bajo potestad del admin** | ✅ | El destino usa `Auth::id()` = admin (movimiento e id_usuario_autoriza); el solicitante queda en `id_usuario_solicita`. Trazabilidad completa. |
| 12 | **Servicios destino compatibles** | ✅ | `CancelarItemService`/`RegistrarMovimientoService` aceptan `id_autorizacion` opcional; sin él (ADMIN directo S7/S5) se comportan igual — suite previa intacta. |
| 13 | **Migración D1 + paridad** | ✅ | Columna `datos` JSONB portable; ciclo `up()`/`rollback`/`migrate` verificado en pgsql; suite verde en ambos motores. |
| 14 | **Validación en dos capas** | ⚠️ | Forma polimórfica por `tipo` en `SolicitarAutorizacionRequest` (`Rule::requiredIf`); estado en el servicio. La existencia del sujeto se valida en el servicio (404), no en el Request — ver R1. |
| 15 | **Eventos con assert** | ⚠️ | `AutorizacionSolicitada`/`AutorizacionResuelta` se emiten en la transacción; se verifican indirectamente (auditoría + efecto). Sin `Event::fake` directo — ver R2. |

**Pruebas ejecutadas en esta revisión:** `php artisan test` → **188 passed (461 assertions)** en SQLite; `php artisan test -c phpunit.pgsql.xml` → **188 passed** en **PostgreSQL 17**; `pint --test` → `passed`; ciclo `migrate:fresh --seed` + `rollback --step=1` + `migrate` → limpio en pgsql. El Sprint 9 añade **12 pruebas** (4 Unit, 6 Feature, 2 Integration) sobre las 176 del cierre del S8.

---

## 2. Qué quedó correcto

1. **El flujo de dos niveles reutiliza el núcleo sin reescribirlo.** Aprobar invoca los mismos `CancelarItemService`/`AnularOrdenService`/`RegistrarMovimientoService` de S7/S5; el único cambio fue aceptar un `id_autorizacion` opcional, de modo que la rama ADMIN directo no se tocó y la suite previa quedó intacta.
2. **El enlace es bidireccional y consistente.** La autorización apunta al sujeto (`entidad`/`entidad_id`) y la fila ejecutada apunta de vuelta (`id_autorizacion`), con auditoría de solicitud y resolución. La trazabilidad operador→admin→operación queda cerrada.
3. **Idempotencia de resolución con doble defensa.** `lockForUpdate` + verificación de estado terminal evita la doble ejecución; si el destino falla (orden ya no modificable), toda la transacción se revierte y la solicitud sigue `pendiente`, lista para reintentarse o rechazarse.
4. **La separación de potestades es fiel a la matriz Fase 7.** El operador solo solicita; el permiso directo de las cuatro operaciones 🔐 sigue vetado; el admin resuelve. Probado por los 403 directos y el 403 de aprobación del operador.
5. **Migración mínima y portable.** La carencia del esquema (payload de la operación pendiente) se cubrió con una columna JSONB nullable y se verificó el ciclo completo en pgsql, preservando la paridad.

---

## 3. Qué quedó incompleto

### Diferido por diseño (no bloquea el cierre del Sprint 9)
- **Impresión/reimpresión y su autorización** (`tickets.reimprimir` 🔐) — Sprint 10.
- **Reportes de autorizaciones** — Sprint 11.
- **Caducidad de solicitudes** — sin vencimiento automático en V1 (la solicitud `pendiente` permanece hasta resolverse).
- **Deduplicación de solicitudes** sobre el mismo sujeto — no bloqueada en V1; el re-disparo del destino sobre un sujeto ya resuelto se revierte por estado.

### Observaciones a gestionar (no bloqueantes)
- **Existencia del sujeto validada en el servicio** (404), no en el Request (R1).
- **Eventos sin `Event::fake` directo** (R2, hilo abierto desde S6/S7/S8).

---

## 4. Riesgos

| ID | Riesgo | Severidad | Impacto |
|----|--------|-----------|---------|
| R1 | **El Request no valida existencia del sujeto.** `SolicitarAutorizacionRequest` valida forma (tipo/ids/cantidad), pero la existencia/pertenencia del sujeto la resuelve el servicio con `findOrFail` (→404). | Baja | Coherente con el contrato del proyecto ("sujeto de otro tenant → 404"); un id ausente da 422 por forma, uno inexistente da 404 por servicio. Si se prefiere 422 uniforme, mover a reglas `exists` scoped. |
| R2 | **Eventos de autorización sin `Event::fake`.** `AutorizacionSolicitada`/`AutorizacionResuelta` se prueban indirectamente (auditoría + efecto), no con assert directo de despacho. | Baja | Un futuro consumidor (notificaciones, reportes S11) podría romperse sin que la suite avise. Añadir el assert al cablear el consumo (mismo criterio que S6 R4 / S8 R3). |
| R3 | **Sin deduplicación de solicitudes pendientes.** Dos `pendiente` sobre el mismo ítem/orden son posibles; al aprobar la segunda, el destino se revierte por estado (ítem ya cancelado / orden no modificable). | Baja | Sin efecto incorrecto (la segunda falla limpia), pero genera ruido en la bandeja. Si molesta, añadir índice/verificación "una pendiente por sujeto". |
| R4 | **`AnularOrden` no graba `id_autorizacion`.** La tabla `ordenes` no tiene esa FK (solo `detalle_orden`/`movimientos_inventario`); el enlace anular↔autorización es unidireccional vía `entidad`/`entidad_id`. | Baja | Consistente con el DER V1.2; la trazabilidad existe (la autorización apunta a la orden y la auditoría registra ambas). Anotado por completitud. |
| R5 | **Savepoint anidado en la aprobación.** El destino abre su propia `DB::transaction` dentro de la transacción de resolución. | Baja | Correcto en pgsql y SQLite (suite verde); el savepoint es redundante pero inocuo (mismo criterio que S8 R6). |

---

## 5. Qué debe corregirse antes del Sprint 10

**Bloqueantes:** ninguno. El Sprint 9 cumple su DoD —flujo de dos niveles de extremo a extremo, enlace de la operación ejecutada, idempotencia de resolución, separación de potestades fiel a la matriz, suite verde en SQLite y PostgreSQL, migración con ciclo up/down verificado, aislamiento probado, policy, auditoría transaccional, validación en dos capas— sin defectos abiertos ni P0.

**Recomendados (cerrar para no arrastrar deuda):**
1. **Añadir asserts de eventos** (`Event::fake` para `AutorizacionSolicitada`/`AutorizacionResuelta`) al cablear sus consumidores (R2).
2. **Decidir la deduplicación** de solicitudes pendientes por sujeto si la bandeja lo requiere (R3).

**Heredados (deuda planificada):** decisión formal de RLS, aritmética monetaria en float, unificación del patrón de auditoría (Observer en S12), riesgos del folio (S7).

---

## 6. Conclusión

El **Sprint 9 está bien implementado, es idiomático y está en verde** en ambos motores (188 tests, 461 aserciones, Pint limpio). Lo esencial —el flujo "operador solicita → admin aprueba → sistema ejecuta el servicio destino → sistema audita", el enlace bidireccional de la operación ejecutada, la idempotencia de resolución con reversión limpia, y la separación de potestades fiel a la matriz Fase 7— está construido y probado. La única migración se justifica por la necesidad de conservar el payload de la operación pendiente y se verificó su ciclo completo en PostgreSQL, preservando la paridad. Se reutilizaron los servicios del núcleo sin reescribirlos, manteniendo intacta la suite previa.

Las observaciones restantes son honestas y **no bloqueantes**: validación de existencia en el servicio, eventos sin assert directo, ausencia de deduplicación y el savepoint anidado. Ninguna compromete el inicio del Sprint 10.

---

# Dictamen

## APROBADO PARA SPRINT 10

**Motivo:** la capa de Autorizaciones está entregada conforme al roadmap y al DoD, verificada en SQLite y PostgreSQL, sin bloqueantes ni P0. El Sprint 10 (Impresión: comanda, ticket, fallback PDF, M13) tiene sus dependencias satisfechas: **Órdenes (S7)** con la comanda (`ItemConfirmado`), **Pagos (S8)** con `OrdenPagada` como punto de integración del ticket, y la **Configuración (S3)** con los datos de impresión.

**Recomendación de arranque:** consumir `OrdenPagada`/`ItemConfirmado` para generar `tickets.contenido_json`, enrutar por impresora con fallback a PDF (P15), y aprovechar para añadir los asserts de eventos pendientes (Sección 5, punto 1), incluido el de reimpresión auditada (`ticket.reimpreso`, 🔐 para el operador vía el flujo recién construido).
