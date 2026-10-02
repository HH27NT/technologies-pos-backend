# SPRINT_12_ALCANCE.md — Consolidación de auditoría y endurecimiento

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Fecha:** 2026-07-01
**Base:** `RoadmapImplementacion.md` (Sprint 12, §4 Pruebas §539–542, §5 DoD, §compuerta de cierre §561), `EspecificacionFuncional.md` (M15, matriz Fase 7, reglas globales 21–23, P14/P16/P17/P20), `DatabaseDictionary.md` (`auditoria`). Arranca sobre **todo el sistema ya construido (S0–S11)**.

> **Contexto.** El Sprint 12 cierra el MVP V1: verifica la **cobertura transversal** —auditoría completa, aislamiento multi-tenant, concurrencia y la **matriz de permisos** (cada rol × cada acción, incluidos los 🔐)— y confirma que la implementación respeta las decisiones ratificadas **P16** (contraseñas) y **P20** (costo del margen) antes de congelar el contrato de API. Cubre los pasos 9 y 11 de §23 en su faceta de **verificación**.

---

## 0. Punto de partida (lo que ya existía de sprints previos)

Al llegar al S12, la cobertura que el roadmap planeaba construir **ya está construida**, sprint a sprint, porque el **DoD #5 de cada sprint** exigía "auditoría implementada" para sus acciones sensibles:

- **Auditoría de negocio (inline en cada servicio, dentro de la transacción §15):** caja (`caja.abierta/cerrada`), órdenes (`orden.creada/item_agregado/item_modificado/comanda/descuento/item_cancelado/anulada`), pagos (`orden.pago_registrado/pagada`), inventario (`inventario.entrada/ajuste/merma/rotura/consumo_interno/venta/reversa_venta`), autorizaciones (`autorizacion.solicitada/aprobada/rechazada`).
- **Auditoría CRUD de maestros (inline en los servicios `Guardar*`/`Gestionar*`):** `producto.*`, `categoria.*`, `mesa.*`, `impresora.*`, `insumo.*`, `proveedor.*`, `unidad_medida.*`, `receta.*`, `configuracion.actualizada`, `establecimiento.creado/actualizado/activado/admin_asignado`, `usuario.creado/modificado`.
- **Impersonación (P17):** ya auditada en `ResolveTenant` (`soporte.impersonacion`) y marcada en `TenantContext::impersonando()`.
- **Reimpresión (P14):** ya auditada en S10 (`ticket.reimpreso`).
- **Consistencia transaccional (§15):** `RegistrarAuditoriaService` se invoca **dentro** de la `DB::transaction` de cada servicio; si la acción se revierte, su auditoría también.
- **Pruebas transversales previas:** aislamiento (`TenantIsolationTest`, S0, + tests por módulo con `→ 404` cross-tenant), concurrencia (`CajaConcurrenciaTest`, `OrdenConcurrenciaTest` con índices únicos parciales), P16 (`PoliticaPasswordTest`), P20 (`ReporteGestionTest`, S11).

---

## 1. Decisiones de producto ratificadas

| # | Decisión abierta | Resolución |
|---|---|---|
| D1 | **El roadmap planea "Observer + trait `Auditable`" para la auditoría CRUD de maestros, pero esos registros YA se escriben inline en cada servicio.** Cablear el Observer ahora **duplicaría** cada registro (`producto.creado` del servicio + `producto.created` del Observer). | **No se introduce el Observer.** La auditoría ya está **consolidada por la vía inline**, que además garantiza la consistencia transaccional (§15) mejor que un Observer (que dispara en `saved`/`deleted`, no necesariamente dentro de la transacción del servicio). El Sprint 12 **verifica** la cobertura en vez de reconstruirla. Se documenta la desviación respecto del roadmap: la arquitectura elegida es "auditoría inline en servicios", no "Observer sobre modelos". El trait `Auditable` (filtrado de campos sensibles) **se conserva**: lo usa el subscriber de usuarios para el diff. |
| D2 | **Diff antes/después en JSONB (§540).** | El mecanismo de diff **ya existe y se usa**: `Auditable::filtrarParaAuditoria` excluye campos sensibles (`password_hash`, `remember_token`, `updated_at`) y `usuario.modificado` persiste `datos_antes`/`datos_despues`. El S12 lo **verifica por unidad** (el diff no filtra secretos) en lugar de generalizarlo por Observer. |
| D3 | **Alcance de la "matriz de permisos completa".** | Se materializa como un **Feature test data-driven** que recorre los límites de seguridad: el OPERADOR recibe **403** en las operaciones de solo-admin (catálogo, usuarios, config, `cancelar_item`/`anular`/`entrada`/`ajustar` directos 🔐, reportes de gestión, auditoría) y **pasa** en las suyas (caja, órdenes, cobro, merma, solicitar autorización, reportes limitados); el ADMIN recibe **403** en las de plataforma (establecimientos, auditoría global); el SUPER_ADMIN pasa por `Gate::before`. |
| D4 | **Código de producción del sprint.** | **Ninguno.** El roadmap indica "Endpoints: ninguno nuevo"; la auditoría y las policies ya están. El S12 es un sprint de **verificación y endurecimiento**: solo añade pruebas y documentación (este ALCANCE + el REVIEW). **Sin migraciones, sin cambios de seeder, sin servicios nuevos.** |

**Contratos ya fijados por el roadmap (se verifican, no se reabren):**
- **Un usuario de A nunca obtiene datos de B** (aislamiento por `TenantScope`; `auditoria` acotada por `id_establecimiento`).
- **La auditoría financiera vive en la transacción de su acción** (§15): si la acción se revierte, su auditoría también.
- **El operador nunca tiene el permiso directo** de las 4 operaciones 🔐 (solo puede solicitarlas, S9).
- **P16** (mínimo 8, configurable, sin bloqueo) y **P20** (costo por `controla_inventario`) implementadas tal como se ratificaron.

---

## 2. Módulos y endpoints

**Ninguno nuevo.** El sprint verifica los existentes bajo la matriz de permisos completa (cada rol × cada acción, incluidos los 🔐). No hay servicios, controladores, policies, resources, migraciones ni seeders nuevos.

---

## 3. Contratos clave (lo que se verifica)

- **Matriz de permisos.** Para cada rol, los endpoints protegidos devuelven el veredicto esperado (200/201 vs 403). El foco son los **límites de seguridad**: operador → 403 en solo-admin; admin → 403 en plataforma; el operador **no** posee `ordenes.cancelar_item`/`ordenes.anular`/`inventario.entrada`/`inventario.ajustar` (siguen 403 directo, solo vía autorización).
- **Cobertura de auditoría.** Un recorrido representativo (CRUD de maestro + acción de negocio + impersonación P17 + reimpresión P14) deja su fila en `auditoria` con `usuario`, `accion`, `entidad`, `entidad_id`, `ip` y `created_at`.
- **Consistencia transaccional (§15).** Cuando una acción que audita **se revierte** (p. ej. `ResolverAutorizacionService::aprobar` cuyo servicio destino falla porque el sujeto dejó de ser modificable), **no queda** ni el efecto ni su fila de auditoría; la autorización permanece `pendiente`.
- **Diff sin secretos.** `filtrarParaAuditoria` nunca incluye `password_hash`/`remember_token` en `datos_antes`/`datos_despues`.
- **Aislamiento multi-tenant (API).** Un actor del tenant B recibe **404** al pedir recursos (orden, ticket, insumo, sesión de caja, autorización) del tenant A; la auditoría del admin de B no incluye la de A.
- **Concurrencia.** Dobles aperturas de caja y dobles órdenes abiertas por mesa fallan por índice único parcial + bloqueo (ya probado en S6/S7; se ratifica en verde).
- **P16 / P20.** Verificados (ya en verde desde S1/S11; se confirman como parte de la compuerta de cierre §561).

---

## 4. Pruebas (DoD §4 / §539–542)

- **Unit** (`tests/Unit/Auditoria`):
  - `AuditableTest` — `filtrarParaAuditoria` excluye `password_hash`/`remember_token`/`updated_at` y conserva el resto (el diff no filtra secretos, §540).
- **Feature** (`tests/Feature/Endurecimiento`):
  - `MatrizPermisosTest` — la matriz rol × acción: operador 403 en solo-admin y 🔐 directos, 200/201 en las suyas; admin 403 en plataforma; ambos veredictos con los mismos datos de prueba (data-driven).
- **Integration** (`tests/Integration/Endurecimiento`):
  - `CoberturaAuditoriaTest` — CRUD de maestro, acción de negocio, impersonación (P17) y reimpresión (P14) dejan su fila en `auditoria`; una aprobación de autorización que falla **no** deja auditoría (rollback §15).
  - `AislamientoApiTest` — recursos de A → 404 para un actor de B a través del API (orden/ticket/insumo/caja/autorización).
- **Reutilizadas (se mantienen en verde, no se reescriben):** `TenantIsolationTest` (S0), `CajaConcurrenciaTest` (S6), `OrdenConcurrenciaTest` (S7), `PoliticaPasswordTest` (P16), `ReporteGestionTest` (P20).
- **Estado esperado:** suite **verde en SQLite y PostgreSQL 17**, **Pint limpio**, **sin migraciones** (paridad por construcción). Se parte de **229** (cierre del S11).

## 5. Fuera de alcance

- **Refactor a Observer** de la auditoría CRUD (D1): descartado; duplicaría registros y no aporta sobre la cobertura inline ya probada.
- **Row-Level Security (RLS) de PostgreSQL**: decisión de infraestructura pendiente desde S0; no se activa en el MVP (el aislamiento por `TenantScope` está probado).
- **Aritmética monetaria en decimal** (hoy float en presentación): fuera del MVP; los importes se congelan en la orden.

## 6. Deuda heredada (sin cambios + nota)

Decisión formal de RLS y aritmética monetaria en float quedan como deuda **explícita post-MVP**. **Nota de cierre (§561):** con la matriz de permisos, el aislamiento, la concurrencia y la cobertura de auditoría verificadas, y P16/P20 confirmadas, el **contrato de API del MVP V1 queda listo para congelar**. La única desviación documentada respecto del roadmap es arquitectónica y deliberada: la auditoría se consolidó **inline en los servicios** (consistente con §15) en lugar de por Observer.
