# SPRINT_2_ALCANCE.md — Cierre de la capa 1 (M03 Configuración)

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Fecha:** 2026-06-16
**Base:** dictamen "APROBADO PARA SPRINT 2" de `SPRINT_1_REVIEW.md` (con la indicación de **arrancar cerrando M03**) y el pendiente registrado en `SPRINT_1_ALCANCE.md` §2.

> **Contexto.** El "Sprint 1" entregado ya adelantó **M02 (establecimientos)** y **M04 (usuarios/roles)**. Por eso, del Sprint 2 del roadmap (M02 + M03) **lo único que restaba era M03 Configuración (edición)**. Este sprint cierra esa pieza y salda la deuda no bloqueante P16, completando la **capa 1 de identidad y administración del tenant**.

---

## 1. Entregado

### M03 · Configuración del establecimiento (edición)
- **Endpoints** (cadena `auth:sanctum → resolve.tenant → tenant.activo`):
  - `GET /api/v1/configuracion` — devuelve la configuración 1:1 del tenant.
  - `PUT /api/v1/configuracion` — edita datos de ticket, impresión automática, stock mínimo global e impuesto (P11).
- **Servicio:** `ActualizarConfiguracionService` — atómico (`DB::transaction`), audita `configuracion.actualizada` **dentro de la misma transacción** (§15). Regla de estado: si `aplica_impuesto` queda activo, `tasa_impuesto` debe ser > 0 (`ConfiguracionInvalidaException`, 422).
- **Form Request:** `ActualizarConfiguracionRequest` — validación de forma (tasa 0–100, stock ≥ 0, parciales con `sometimes`).
- **Policy:** `ConfiguracionEstablecimientoPolicy` — `view`/`update` con permiso `configuracion.editar` (ADMIN del tenant); super_admin vía `Gate::before`.
- **Resolución por tenant:** la fila se obtiene siempre por el `id_establecimiento` del `TenantContext`, nunca por un id del cliente.

### P16 · Política de contraseñas configurable (deuda no bloqueante saldada)
- `config/pos.php` → `pos.password.min_length` (env `POS_PASSWORD_MIN_LENGTH`, default 8), sin bloqueo por intentos fallidos.
- `App\Support\Validation\PoliticaPassword::regla()` como fuente única, consumida por `CrearUsuarioRequest`, `ActualizarUsuarioRequest` y `CrearEstablecimientoRequest` (antes `Password::min(8)` literal en cada uno).

---

## 2. Pruebas (DoD §2/§4)

- **Unit** `ActualizarConfiguracionServiceTest` (5): actualiza datos + audita; activa impuesto con tasa válida; rechaza impuesto activo sin tasa / con tasa 0; un fallo de estado no persiste cambios ni auditoría.
- **Feature** `ConfiguracionTest` (6): consulta y edición por ADMIN; 422 por impuesto sin tasa y por tasa fuera de rango; operador 403 (ver/editar); **aislamiento** (cada ADMIN edita solo su configuración).
- **Feature** `PoliticaPasswordTest` (2): respeta la longitud por defecto; la longitud mínima es configurable.
- **Estado:** suite completa **45/45 verde** en SQLite; **Pint** limpio. (La verificación contra PostgreSQL corre en CI; los cambios no añaden migraciones ni columnas, por lo que la paridad de esquema no varía respecto a lo ya verificado en el Sprint 1.)

---

## 3. Estado de la capa 1

Con M03 cerrado, la **capa 1 (identidad y administración del tenant)** queda **completa**: M01 (auth) + M15 (auditoría base) + M02 (establecimientos) + M03 (configuración) + M04 (usuarios/roles). El siguiente paso del roadmap es el **Sprint 4 — Catálogo de venta** (categorías, productos, mesas, impresoras).

## 4. Deuda no bloqueante aún abierta (gestionar en sprints siguientes)

- **Factories 4/22** — se completan cuando cada módulo entre en pruebas (Convenciones §7.4).
- **Decisión formal de RLS** (Arq §7) — diferida de facto.
- **Divergencia de timestamps (R4 del Sprint 0)** — ratificar o revertir en el DER.
- **Patrón de auditoría mixto (R7)** y **auditoría de impersonación por-request (R6)** — unificar/optimizar.
