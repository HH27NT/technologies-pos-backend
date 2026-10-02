# SPRINT_0_REVIEW.md — Revisión Arquitectónica del Sprint 0

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Revisor:** Arquitecto de Software Senior
**Fecha:** 2026-06-13
**Base de evaluación:** `ArquitecturaBackend.md` (§3, §5–§10, §15, §19, §21, §23), `RoadmapImplementacion.md` (Sprint 0, §5 DoD), `Convenciones.md` (§1–§7).
**Objeto:** validar exclusivamente el **Sprint 0 — Cimientos e infraestructura de datos**. No se evalúa funcionalidad de módulos (correctamente fuera de alcance).

---

## 0. Encuadre de alcance (qué exige el Sprint 0 y qué NO)

El Roadmap define el Sprint 0 como infraestructura, **sin nada funcional de cara al usuario**. Sus entregables son: esquema migrado y reversible; seeders (catálogos globales, roles/permisos, super_admin); los 22 modelos Eloquent con relaciones/casts/traits; infraestructura multi-tenant (`TenantContext`, `TenantScope`, `BelongsToTenant`, `IncluyeGlobales`); traits de soporte (`Auditable`, `GeneraFolio`); y un test de aislamiento mínimo.

**Consecuencia de evaluación:** varios puntos solicitados en esta revisión —**Middleware**, **Policies**, **Service Layer**, y la **escritura** de auditoría (Observer + listener + `RegistrarAuditoriaService`)— **pertenecen al Sprint 1 o posteriores** según la Arquitectura §9/§10 y el Roadmap (Sprint 1: "auditoría base = trait + listener + servicio"; "policies vacías listas para rellenar"). Por tanto se evalúan como **diferidos por diseño**, no como defectos del Sprint 0. Donde el Sprint 0 sí entrega una pieza (p. ej. el *trait* `Auditable`, o el *mecanismo* de tenant), se evalúa su corrección.

---

## 1. Verificación punto por punto

Leyenda: ✅ Correcto · ⚠️ Correcto con observación/riesgo · ⏭️ Diferido por diseño (Sprint 1+) · ⛔ Defecto

| # | Área | Estado | Evidencia / Notas |
|---|------|--------|-------------------|
| 1 | **Configuración de Sanctum** | ✅ | `laravel/sanctum ^4.3` instalado; `personal_access_tokens` migrada; `Usuario` usa `HasApiTokens`. `config/sanctum.php` → `expiration = null` (cumple **P18**, sesión sin caducidad). |
| 2 | **Configuración de Spatie Permission** | ✅ | `teams => true`, `team_foreign_key => id_establecimiento` (§8 / Convenciones §5.2). 32 permisos del catálogo §8 sembrados; roles `super_admin/admin/operador` con sets de la matriz Fase 7 (admin=26, operador=10). `usuarios.id_rol` conservado como **cache** (reconciliación DER↔Spatie). |
| 3 | **Base Multi-Tenant** | ✅ | `TenantContext` (singleton, registrado en `AppServiceProvider`), `BelongsToTenant` (scope + autollenado), `IncluyeGlobales` (híbrido `unidades_medida`). Discriminador `id_establecimiento` en todas las tablas operativas; globales sin tenant; `unidades_medida`/`usuarios`/`auditoria` nullable según DER. |
| 4 | **TenantScope** | ⚠️ | Implementado e idiomático; exime correctamente cuando no hay contexto (super_admin/consola). **Probado** (aislamiento + híbrido en verde). Observación: el aislamiento en tiempo de petición depende de que un middleware **fije** el contexto, y ese middleware aún no existe (ver #5). Hoy el contexto solo lo pone el test. |
| 5 | **Middleware** | ⏭️ | `ResolveTenant`, `EnsureTenantActivo`, `EnsureCajaAbierta` **no implementados**; son Sprint 1+ (Arq §10, Roadmap Sprint 1). `bootstrap/app.php` con cadena vacía. Correcto para Sprint 0 (no hay endpoints), pero es el **primer trabajo obligatorio del Sprint 1**. |
| 6 | **Policies base** | ⏭️ | Ninguna policy creada. Arq §9 y Roadmap ubican las policies en Sprint 1+ ("policies vacías por modelo"). No requerido en Sprint 0. |
| 7 | **Service Layer base** | ⏭️ | Sin `app/Domain/**`. El Roadmap es explícito: "Aún no hay servicios de dominio; se entregan los componentes de soporte". Correcto. |
| 8 | **Auditoría base** | ⚠️ / ⏭️ | Entregado el *trait* `Auditable` (capacidad + filtrado de campos sensibles) — correcto para Sprint 0. La **escritura** real (Observer + listener `RegistrarAuditoria` + `RegistrarAuditoriaService`, dentro de transacción, §15) es Sprint 1. Hoy **no se audita nada todavía**; la tabla `auditoria` existe y está lista. |
| 9 | **Estructura de carpetas** | ✅ | Coincide con Arq §3 / Convenciones §1.4 para lo que existe: `app/Models/` plano (21 modelos), `app/Support/Tenant/`, `app/Support/Concerns/`. Carpetas aún no necesarias (`Domain/`, `Http/{Requests,Resources,Middleware}`, `Policies/`, `Listeners/`, `Jobs/`) no se crearon vacías — se añadirán cuando el sprint que las usa llegue. |
| 10 | **Convenciones de código** | ✅ | FK `id_establecimiento` (no `establecimiento_id`); dominio en español, infraestructura en inglés; tablas `snake_case` plural; modelos `PascalCase` singular; decimales `DECIMAL(12,2)/(12,3)/(5,2)` (sin float); ENUM exactos del DER; un modelo por tabla **sin lógica de negocio**; migraciones con `down()` reversible; **Pint en verde**. |

**Pruebas ejecutadas:** 5/5 en verde (10 aserciones) — aislamiento por tenant, autollenado de `id_establecimiento`, unidades híbridas globales+propias. Seeders idempotentes verificados (re-seed sin duplicados).

---

## 2. Qué quedó correcto

1. **Esquema completo y coherente con el DER V1.2** — 26 migraciones (21 dominio + Spatie con `teams` + Sanctum + soporte auth), en orden de dependencias de FK. Incluye los ajustes V1.2: `unidades_medida` híbrido, `aplica_impuesto`/`tasa_impuesto`, `propina` reservada, y `detalle_orden.enviado`.
2. **Reordenamiento correcto de FKs** — `autorizaciones` se creó antes de `detalle_orden`/`movimientos_inventario` (que la referencian), evitando el riesgo de `migrate` roto que el propio Sprint 0 advertía.
3. **Único `CASCADE` autorizado** aplicado solo en `detalle_orden.id_orden`; el resto restrictivo (Convenciones §3.3).
4. **Unicidades por tenant** declarativas: folio, número de mesa, email/username, configuración 1:1, par receta producto+insumo.
5. **Índices únicos parciales PostgreSQL** definidos en migración (una caja `abierta` por establecimiento; una orden `abierta` por mesa) + índice parcial de stock bajo.
6. **Infraestructura multi-tenant funcional y probada**, incluido el scope híbrido de unidades de medida (P4).
7. **Reconciliación roles↔Spatie** bien encuadrada: catálogo de permisos global, roles con team, `id_rol` como cache; **super_admin como rol global** resuelto vía tenant nulo + (futuro) `Gate::before`, sin forzar un pivote `model_has_roles` con team nulo (que la BD rechaza correctamente).
8. **Cumplimiento de P18** (tokens sin expiración) y de las convenciones de naming/estilo (Pint limpio).

---

## 3. Qué quedó incompleto

Distinguir **incompleto-por-diseño** (Sprint 1+) de **pendiente-real del Sprint 0**:

### Diferido por diseño (no bloquea el cierre del Sprint 0)
- Middleware de tenant/caja (Sprint 1).
- Policies y `Gate::before` (Sprint 1).
- Service Layer de dominio (Sprint 2+).
- Escritura de auditoría: Observer + listener + servicio (Sprint 1).

### Pendiente real / a cerrar
- **P0 — Validación contra PostgreSQL.** El entorno de desarrollo **no tiene `pdo_pgsql`**; todo se validó contra **SQLite en memoria**, donde los bloques `DB::statement` de índices parciales se **omiten por driver**. En consecuencia, **el esquema y las garantías de concurrencia nunca se han ejecutado en el motor objetivo**. El entregable "esquema migrado" del Roadmap solo está probado en SQLite.
- **Factories 4/22.** Solo `Establecimiento`, `Usuario`, `Proveedor`, `UnidadMedida`. Convenciones §7.4 espera cobertura de las 22 tablas (se construirá incrementalmente por sprint, pero queda anotado).
- **Decisión de Row-Level Security (RLS).** El Sprint 0 pedía explícitamente **decidir** si se activa ahora o se difiere (Arq §7). No se tomó la decisión formal (de facto, diferida).
- **CI sobre PostgreSQL.** Convenciones §9.4 exige pipeline en verde contra PostgreSQL; aún no existe.

---

## 4. Riesgos

| ID | Riesgo | Severidad | Impacto |
|----|--------|-----------|---------|
| R1 | **Garantías de concurrencia no verificadas.** Los índices únicos parciales ("una caja abierta", "una orden abierta por mesa") solo existen en PostgreSQL y nunca se ejecutaron; en SQLite se saltan. Un fallo de sintaxis o de definición no se detectaría hasta el primer `migrate` real. | **Alta** | Si fallan, se cae el respaldo en BD de reglas críticas (Arq §19); la unicidad quedaría solo a nivel app. |
| R2 | **Paridad de esquema SQLite ≠ PostgreSQL.** `jsonb`→text y `enum`→varchar+check en SQLite; tipos/PK compuestas/índices podrían comportarse distinto en pg. | Media | Sorpresas en el primer despliegue pg. |
| R3 | **Autorización de super_admin inexistente aún.** Depende 100% de `Gate::before`, que es Sprint 1. Además `esSuperAdmin()` se basa en el invariante "solo el super_admin tiene tenant nulo"; si se violara, habría escalada. | Media | Hasta Sprint 1, el super_admin no tiene permisos efectivos; el invariante debe documentarse y protegerse. |
| R4 | **Divergencias respecto al DER literal.** Se añadieron `movimientos_inventario.created_at` y `timestamps()` a `autorizaciones`; y se **omitieron** `created_at/updated_at` en maestros que el DER lista solo con `deleted_at` (productos, insumos, mesas, etc.). Esto crea una inconsistencia con Convenciones §3.2 (que estandariza el trío de timestamps). | Media | Reportes/ordenación que asuman `created_at` en maestros fallarían; divergencia no ratificada en el DER. |
| R5 | **Compensación de P18 no implementada.** Tokens sin caducidad exigen revocación explícita al desactivar usuario/establecimiento (Convenciones §5.1). No existe aún (Sprint 1/2). | Media | Ventana de seguridad si se difiere sin registro. |
| R6 | **`GeneraFolio` usa `withoutGlobalScopes()` fuera de `Support/Tenant`.** Convenciones §2.10 restringe el bypass de scope a la infraestructura de tenant y al flujo super_admin. | Baja | Edge de convención; revisar al cablear el folio en Sprint 7. |
| R7 | **Factories incompletas (4/22).** | Baja | Limita la cobertura de pruebas de los próximos sprints hasta completarlas. |

---

## 5. Qué debe corregirse antes de Sprint 1

**Bloqueantes (P0) — hacer al inicio del Sprint 1, antes de añadir middleware/endpoints:**
1. **Habilitar `pdo_pgsql`/`pgsql`** y ejecutar `php artisan migrate:fresh --seed` contra **PostgreSQL real**. Confirmar que: (a) las 26 migraciones corren y son reversibles; (b) los **tres índices parciales** se crean; (c) los seeders quedan idempotentes en pg.
2. **Añadir una prueba de concurrencia/índice parcial en PostgreSQL** (doble apertura de caja / doble orden abierta por mesa que falle por el índice). Aunque su DoD formal es de Sprints 6–7, conviene una prueba mínima ahora para validar R1 cuanto antes. El pipeline de CI debe correr contra pg (Convenciones §7.4/§9.4).

**Recomendados (P1) — cerrar para no arrastrar deuda:**
3. **Decidir y documentar RLS** (activar en Sprint 0/1 vs diferir formalmente), como pedía Arq §7.
4. **Ratificar o revertir las divergencias de timestamps (R4)** en el DER/Diccionario: o se asienta `created_at` en maestros y en `movimientos_inventario`/`autorizaciones` como cambio V1.2.x, o se revierte. Evitar divergencia silenciosa.
5. **Documentar el invariante del super_admin** (tenant nulo ⇔ super_admin) y planificar `Gate::before` como primera tarea de autorización del Sprint 1; registrar la compensación de P18 (revocación de tokens) en el backlog del Sprint 1/2.

**Menores (P2):**
6. Completar factories faltantes a medida que cada modelo entre en pruebas.
7. Revisar el uso de `withoutGlobalScopes()` en `GeneraFolio` al integrarlo en Sprint 7.

---

## 6. Conclusión

El **alcance real del Sprint 0** (esquema, infraestructura multi-tenant, modelos, seeders, traits de soporte y test de aislamiento) está **implementado de forma correcta, faithful al DER V1.2 y a las convenciones, con pruebas y linter en verde**. Los elementos "middleware / policies / services / escritura de auditoría" no son del Sprint 0 y están **correctamente diferidos** al Sprint 1+. Los riesgos identificados son **de verificación y entorno** (sobre todo la no-ejecución contra PostgreSQL), no defectos de diseño del código entregado, y se absorben naturalmente al inicio del Sprint 1 —que comienza justamente por migraciones e infraestructura de tenant—.

La única reserva con peso es **R1/P0**: las garantías de concurrencia en BD no se han ejecutado en el motor objetivo. Se aprueba **con la condición explícita** de que la primera acción del Sprint 1 sea la validación de migraciones e índices parciales contra PostgreSQL antes de construir middleware o endpoints.

---

# Dictamen

## APROBADO PARA SPRINT 1

**Condicionado a la ejecución de los puntos P0 (1 y 2) de la Sección 5 como primera tarea del Sprint 1**, antes de implementar middleware o cualquier endpoint. Los puntos P1 deben cerrarse dentro del Sprint 1; los P2 pueden gestionarse como deuda planificada.
