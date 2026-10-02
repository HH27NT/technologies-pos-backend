# SPRINT_1_ALCANCE.md — Alcance real entregado en el "Sprint 1"

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Fecha:** 2026-06-15
**Propósito:** dejar trazabilidad explícita de qué módulos se implementaron realmente bajo la etiqueta "Sprint 1", qué quedó pendiente y cuál es el alcance efectivo, conforme al bloqueante #4 del `SPRINT_1_REVIEW.md`.

> **Contexto.** El `RoadmapImplementacion.md` define Sprint 1 = M01 (auth) + auditoría base. La entrega real **adelantó** trabajo de los Sprints 2 y 3 del roadmap. Este documento concilia el roadmap con lo efectivamente construido; **no** modifica el roadmap ni el alcance funcional del MVP.

---

## 1. Módulos IMPLEMENTADOS

| Módulo (roadmap) | Sprint roadmap | Estado | Endpoints / piezas entregadas |
|---|---|---|---|
| **M01 · Autenticación y control de acceso** | Sprint 1 | ✅ Completo | `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`, **`POST /auth/recuperar`**. Sanctum, `ResolveTenant`, `EnsureTenantActivo`, `Gate::before` super_admin, impersonación P17 auditada, compensación P18 (revocación de tokens). |
| **M15 · Auditoría (infraestructura base)** | Sprint 1 | ✅ Completo | `RegistrarAuditoriaService`, subscriber síncrono `RegistrarAuditoria`, trait `Auditable`. Escritura dentro de transacción (§15), con pruebas de integración. |
| **M02 · Plataforma / Establecimientos** | Sprint 2 | ✅ Adelantado | `GET/POST/PUT establecimientos`, `PATCH .../activar`, `POST .../asignar-admin`. Alta atómica (establecimiento + configuración por defecto + roles por team + ADMIN inicial). |
| **M04 · Usuarios y roles del establecimiento** | Sprint 3 | ✅ Adelantado | `GET/POST/PUT usuarios`, `PATCH .../activar`, `POST .../rol`, `GET roles`. Unicidad por tenant, regla del último ADMIN activo, reconciliación Spatie + cache `id_rol`. |

## 2. Módulos PENDIENTES (no entregados)

| Módulo (roadmap) | Sprint roadmap | Estado | Nota |
|---|---|---|---|
| **M03 · Configuración del establecimiento** | Sprint 2 | ⛔ Pendiente | Falta `GET/PUT /configuracion` y `ActualizarConfiguracionService` (editar datos de ticket, impuesto, stock mínimo). La **siembra** de `configuracion_establecimiento` por defecto sí existe (en el alta del establecimiento); solo falta su **edición**. Debe cerrarse al abordar Sprint 2. |
| M05–M16 (catálogos, inventario, caja, órdenes, pagos, autorizaciones, impresión, reportes) | Sprints 4–12 | ⛔ No iniciados | Fuera de alcance; planificados según roadmap. |

## 3. Alcance EFECTIVO del "Sprint 1"

El "Sprint 1" entregado equivale a **M01 + M15(base) + M02(establecimientos) + M04(usuarios/roles)**, es decir, la **capa 1 completa de identidad y administración del tenant**, con la única excepción de **M03 Configuración (edición)**.

Consecuencia para la planificación: el "Sprint 2" siguiente debe **arrancar cerrando M03 Configuración** (lo único que resta de la capa 1) antes de avanzar a la capa 2 (catálogos de venta, Sprint 4 del roadmap).

---

## 4. Estado de los bloqueantes del review (cierre 2026-06-15)

| # | Bloqueante | Estado | Evidencia |
|---|---|---|---|
| 1 | PostgreSQL real (migraciones, índices, constraints, seeders, factories, CI) | ✅ Cerrado | `migrate` / `migrate:rollback` / `migrate:fresh --seed` ejecutados en PostgreSQL 17; 3 índices únicos parciales creados y **enforcing** (probada la unicidad de "una caja abierta"); seeders idempotentes; suite 32/32 verde en pg. CI en `.github/workflows/ci.yml` con servicio PostgreSQL. |
| 2 | `POST /api/v1/auth/recuperar` | ✅ Cerrado | Request + Service + Notification + ruta + override en `Usuario` + 4 pruebas Feature (anti-enumeración, inactivo, validación). |
| 3 | Pruebas de integración de auditoría | ✅ Cerrado | `tests/Integration/Auditoria/RegistrarAuditoriaTest.php`: evento, consistencia transaccional (rollback), impersonación auditada. |
| 4 | Trazabilidad documental | ✅ Cerrado | Este documento + addendum en `SPRINT_1_REVIEW.md`. |

## 5. Deuda heredada NO bloqueante (gestionar dentro de Sprint 2)

- **Factories 4/22.** Solo `Establecimiento`, `Usuario`, `Proveedor`, `UnidadMedida` (suficientes para Sprint 1; las demás se añaden cuando su módulo entre en pruebas — Convenciones §7.4).
- **Decisión formal de Row-Level Security (RLS)** (Arq §7): aún diferida de facto.
- **Divergencia de timestamps (R4 del Sprint 0):** ratificar o revertir en el DER.
- **Política de contraseñas P16 configurable:** hoy `Password::min(8)` literal; convertir a configurable.
- **`pdo_pgsql` en entornos de desarrollo:** habilitado localmente; documentar el requisito para todo el equipo (el CI ya lo instala).
