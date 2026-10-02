# SPRINT_2_REVIEW.md — Revisión Arquitectónica del Sprint 2

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Revisor:** Arquitecto de Software Senior
**Fecha:** 2026-06-17
**Base de evaluación:** `RoadmapImplementacion.md` (Sprint 2, §4 Pruebas, §5 DoD), `SPRINT_2_ALCANCE.md`, `SPRINT_1_REVIEW.md` (dictamen "APROBADO PARA SPRINT 2" con indicación de arrancar cerrando M03), `ArquitecturaBackend.md` (§7–§10, §15, §17, §20), `Convenciones.md` (§2, §4, §5, §17).
**Objeto:** validar el **Sprint 2 — cierre de la capa 1**, que entrega **M03 Configuración del establecimiento (edición)** y salda la deuda no bloqueante **P16 (política de contraseñas configurable)**.

> **Nota de proceso.** Este review se emite de forma **retroactiva** (el código del Sprint 2 ya está integrado y el Sprint 4 ya fue entregado y revisado). Su propósito es cerrar la cadena de *Definition of Done* verificable que sí tuvieron los Sprints 0 y 1, dejando trazabilidad formal de la pieza M03.

---

## 0. Encuadre de alcance (qué exigía el Sprint 2 y qué se entregó)

El Roadmap define el Sprint 2 como **M02 Plataforma/Establecimientos + M03 Configuración**. Como el "Sprint 1" entregado ya **adelantó M02** (alta atómica de establecimiento + configuración + ADMIN + roles), del Sprint 2 del roadmap **lo único que restaba era M03 Configuración (edición)** — exactamente la indicación con la que el `SPRINT_1_REVIEW.md` aprobó el avance.

Por tanto, el alcance efectivo de este sprint es deliberadamente acotado:

- **M03 Configuración (edición):** `GET/PUT /configuracion`, `ActualizarConfiguracionService`, Form Request, Policy y Resource.
- **P16 (deuda no bloqueante saldada):** política de contraseñas configurable como fuente única.

No se detecta *scope creep*: la **siembra** de `configuracion_establecimiento` ya existía en el alta (M02); este sprint añade solo su **edición**, cerrando la capa 1.

---

## 1. Verificación punto por punto

Leyenda: ✅ Correcto · ⚠️ Correcto con observación/riesgo · ⛔ Defecto/Pendiente bloqueante · ⏭️ Diferido por diseño

| # | Área | Estado | Evidencia |
|---|------|--------|-----------|
| 1 | **Endpoints `GET/PUT /configuracion`** | ✅ | `ConfiguracionController::show/update` en la cadena `auth:sanctum → resolve.tenant → tenant.activo` (`routes/api.php`). |
| 2 | **Resolución por tenant, nunca por id del cliente** | ✅ | `configuracionDelTenant()` resuelve la fila 1:1 con `where('id_establecimiento', $tenant->id())->firstOrFail()`. El cliente no envía id. |
| 3 | **`ActualizarConfiguracionService` atómico y auditado §15** | ✅ | `DB::transaction` envolviendo `fill+save` y `auditoria.registrar('configuracion.actualizada', ...)` con diff antes/después **dentro de la misma transacción**. |
| 4 | **Regla de estado P11 (impuesto activo ⇒ tasa > 0)** | ✅ | `validarImpuesto()` evalúa el **estado final** (datos entrantes sobre los actuales) para soportar PUT parcial; lanza `ConfiguracionInvalidaException` (422). |
| 5 | **Validación en dos capas (forma vs. estado)** | ✅ | Forma en `ActualizarConfiguracionRequest` (tasa 0–100, stock ≥ 0, `sometimes`); estado de negocio en el servicio (Convenciones §17, DoD §7). |
| 6 | **Policy `ConfiguracionEstablecimientoPolicy`** | ✅ | `view`/`update` mapeados a `can('configuracion.editar')` (ADMIN del tenant); super_admin vía `Gate::before`. Sin reglas de estado en la policy. |
| 7 | **Excepción de dominio → envoltura JSON** | ✅ | `ConfiguracionInvalidaException extends DomainException` con `statusHttp() = 422` y mensaje en español; traducida por el handler global (consistente con §20). |
| 8 | **API Resource sin fuga de columnas** | ✅ | `ConfiguracionEstablecimientoResource` expone solo el contrato (sin `id_establecimiento`/`deleted_at`/timestamps internos). |
| 9 | **P16 · Política de contraseñas configurable** | ✅ | `config/pos.php` → `pos.password.min_length` (env `POS_PASSWORD_MIN_LENGTH`, default 8); `App\Support\Validation\PoliticaPassword::regla()` como **fuente única**. |
| 10 | **P16 consumido por todos los requests de contraseña** | ✅ | `PoliticaPassword` referenciado en `CrearUsuarioRequest`, `ActualizarUsuarioRequest` y `CrearEstablecimientoRequest` (antes `Password::min(8)` literal en cada uno). |
| 11 | **Sin bloqueo por intentos fallidos (P16)** | ✅ | La política solo fija longitud mínima; no introduce lockout (decisión Fase 9 respetada). |
| 12 | **Aislamiento multi-tenant** | ✅ | Cada ADMIN consulta/edita solo la configuración de su propio `id_establecimiento`; probado en `ConfiguracionTest::test_aislamiento...`. |
| 13 | **Validación contra PostgreSQL** | ✅ | Reproducida la secuencia del CI contra **PostgreSQL 17** real (2026-06-17): `migrate`/`rollback`/`migrate:fresh --seed` en verde y suite completa **67/67 (148 aserciones)** con `phpunit.pgsql.xml` (incluye los 8 tests de M03/P16), `pint --test` `passed`. Ver R1 (cerrada). |

**Pruebas del sprint (verificadas en código):**
- **Unit** `ActualizarConfiguracionServiceTest` (5): actualiza + audita; activa impuesto con tasa válida; rechaza impuesto activo sin tasa / con tasa 0; un fallo de estado **no persiste cambios ni auditoría** (rollback).
- **Feature** `ConfiguracionTest` (6): consulta y edición por ADMIN; 422 por impuesto sin tasa y por tasa fuera de rango; operador 403 (ver/editar); aislamiento por tenant.
- **Feature** `PoliticaPasswordTest` (2): respeta la longitud por defecto; la longitud mínima es configurable.

La suite global del proyecto está hoy en **67/67 verde** (re-ejecutada en esta auditoría sobre SQLite), sin regresión sobre los 45 del Sprint 2.

---

## 2. Qué quedó correcto

1. **M03 resuelto con la mínima superficie correcta.** El sprint hace exactamente lo que restaba de la capa 1 —la **edición** de la configuración 1:1— sin reabrir M02 ni adelantar catálogos. Alcance disciplinado.
2. **Resolución por tenant blindada.** La fila se obtiene siempre desde el `TenantContext`, nunca desde un id del payload; imposible editar la configuración de otro establecimiento aunque se conozca su id.
3. **Atomicidad y auditoría fieles a §15.** La edición y su entrada de `auditoria` viven en la misma transacción; un fallo de estado revierte ambas (probado explícitamente en el Unit de rollback).
4. **Regla P11 bien ubicada y robusta ante PUT parcial.** La validación "impuesto activo ⇒ tasa > 0" se evalúa sobre el estado final combinando datos entrantes y actuales, de modo que un PUT que solo active el impuesto sin enviar tasa también se rechaza correctamente.
5. **Validación en dos capas idiomática.** Forma en el Form Request, estado de negocio en el servicio con excepción de dominio tipada y código HTTP 422 coherente con el contrato de errores del proyecto.
6. **P16 saldado con una sola fuente de verdad.** `PoliticaPassword::regla()` centraliza la política y la consumen los tres requests que validaban contraseña, eliminando el `Password::min(8)` duplicado que el `SPRINT_1_REVIEW.md` había señalado (§3, recomendación 5). Cambiar la política ya no exige tocar cada request.
7. **Cierre limpio de la capa 1.** Con M03 cerrado, la identidad y administración del tenant (M01 + M15-base + M02 + M03 + M04) queda completa, habilitando el avance a la capa 2 (catálogos) que efectivamente se entregó después.

---

## 3. Qué quedó incompleto

### Diferido por diseño (no bloquea el cierre del Sprint 2)
- **Auditoría CRUD por Observer de maestros** — consolidación en el Sprint 12; aquí la auditoría de configuración se hace vía servicio (suficiente para el DoD).
- **Consumo de la configuración por el núcleo** — `tasa_impuesto`/`impresion_automatica`/`stock_minimo_global` son insumos de Órdenes, Pagos e Inventario; se cablean en sus sprints (7, 8, 5). Aquí solo se editan.

### Deuda heredada aún abierta (registrada en `SPRINT_2_ALCANCE.md` §4, sin cambios)
- **Factories 4/22** — se completan cuando cada módulo entra en pruebas.
- **Decisión formal de RLS** (Arq §7) — diferida de facto.
- **Divergencia de timestamps R4** — sin ratificar ni revertir en el DER.
- **Patrón de auditoría mixto (R7)** y **auditoría de impersonación por-request (R6)** — unificar/optimizar (pendientes del Sprint 1).

---

## 4. Riesgos

| ID | Riesgo | Severidad | Impacto |
|----|--------|-----------|---------|
| R1 | ~~**Verificación pg de este sprint apoyada en CI/alcance, no re-ejecutada en la revisión.**~~ **CERRADA (2026-06-17).** Se reprodujo localmente la secuencia exacta del `ci.yml` contra **PostgreSQL 17** real: `migrate`/`rollback`/`migrate:fresh --seed` en verde, **suite 67/67 (148 aserciones)** con `phpunit.pgsql.xml` (incluidos los 8 tests de M03/P16), y `pint --test` `passed`. | ~~Baja~~ Cerrada | Sin impacto residual. |
| R2 | **Acoplamiento futuro a la tasa de impuesto.** `tasa_impuesto` es insumo directo del `TotalizadorOrden` (Sprint 7). Una edición a 0 con impuesto inactivo es válida hoy, pero el totalizador debe leer el par `aplica_impuesto`/`tasa_impuesto` de forma consistente. | Baja | Sin impacto en el Sprint 2; nota de contrato para el Sprint 7 (no calcular impuesto si `aplica_impuesto = false`). |
| R3 | **Semántica de PUT parcial.** `update` usa `sometimes`, por lo que un PUT que omita campos no los borra (comportamiento tipo PATCH bajo verbo PUT). | Baja | Consistente con el resto del proyecto y adecuado para una configuración 1:1; homogeneizar la semántica de verbos antes de congelar el contrato (Sprint 12). |
| R4 | **Auditoría de configuración vía servicio, no por Observer.** Una edición que no pase por el servicio (seeder, fix por consola) no quedaría auditada. | Baja | Aceptable mientras toda escritura pase por el servicio; el Observer del Sprint 12 cierra el hueco. |

---

## 5. Qué debe corregirse antes del Sprint 4 (siguiente paso del roadmap)

**Bloqueantes:** ninguno. El Sprint 2 cierra la capa 1 sin defectos ni P0: no añade esquema, la regla P11 está implementada y probada, la auditoría es transaccional, las policies y el aislamiento cumplen el DoD, y P16 quedó saldado como deuda no bloqueante.

**Recomendados (cerrar para no arrastrar deuda):**
1. ~~Confirmar el último run de CI contra PostgreSQL en verde.~~ **HECHO (2026-06-17):** secuencia del `ci.yml` reproducida contra PostgreSQL 17 real, suite 67/67 verde y Pint limpio (R1 cerrada).
2. **Fijar el contrato de lectura de impuesto para el Sprint 7** (`aplica_impuesto = false` ⇒ el totalizador no suma impuesto, independientemente de `tasa_impuesto`), evitando ambigüedad cuando se cablee el `TotalizadorOrden` (R2).

**Heredados (gestionar como deuda planificada):** RLS, divergencia de timestamps R4, unificación del patrón de auditoría y de la impersonación por-request, factories restantes.

> **Estado real a la fecha de este review:** el Sprint 4 (capa 2, catálogos) **ya fue entregado y aprobado** (`SPRINT_4_REVIEW.md`). Las recomendaciones 1–2 quedan vigentes como deuda menor de cara al Sprint 7, no como condición de avance.

---

## 6. Conclusión

El **Sprint 2 está bien implementado y cierra correctamente la capa 1**. La edición de la configuración del establecimiento (M03) es atómica, auditada dentro de transacción, resuelta siempre por el tenant del contexto, con la regla de impuesto P11 ubicada en el servicio y robusta ante actualizaciones parciales, validación de forma en el Form Request, policy de ADMIN mapeada a permiso y resource sin fuga de columnas internas. Además **salda la deuda P16** señalada por el `SPRINT_1_REVIEW.md`, centralizando la política de contraseñas en una única fuente consumida por los tres requests que la necesitan.

El alcance es **disciplinado y sin scope creep**: entrega solo lo que restaba de la capa 1, sin reabrir M02 ni adelantar la capa 2. **No se arrastra ningún P0** (el esquema ya corrió en el motor objetivo en el Sprint 1 y este sprint no añade DDL). Las observaciones son menores y *forward-looking* (contrato de impuesto para el Sprint 7, semántica de PUT, paridad pg re-confirmable por CI), ninguna bloqueante.

---

# Dictamen

## APROBADO (capa 1 cerrada) — habilita la capa 2

**Motivo:** M03 Configuración (edición) y P16 quedan entregados conforme al roadmap y al DoD, sin bloqueantes ni P0 heredados. Con esto la **capa 1 de identidad y administración del tenant** (M01 + M15-base + M02 + M03 + M04) está **completa**, lo que habilitó —y habilita retroactivamente de forma trazada— el avance a la **capa 2 (Sprint 4 — catálogo de venta)**, ya entregada y aprobada.

**Recomendación:** atender las recomendaciones 1–2 de la Sección 5 como deuda menor antes del Sprint 7 (Órdenes), donde la configuración de impuesto se cablea al totalizador.
