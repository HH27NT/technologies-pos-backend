# SPRINT_6_ALCANCE.md — Caja (ciclo de apertura/cierre con arqueo)

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Fecha:** 2026-06-18
**Base:** `RoadmapImplementacion.md` (Sprint 6, §4 Pruebas §509–512, §5 DoD), `EspecificacionFuncional.md` (M10, CU-05/CU-06, F8/F9, matriz Fase 7), `DatabaseDictionary.md` (tabla `sesiones_caja`). Arranca sobre la **capa 1** (identidad/tenant/auditoría) y es independiente de las capas 2 (catálogo, S4) y de inventario (S5).

> **Contexto.** El Sprint 6 abre el **núcleo transaccional**. Implementa el **ciclo de caja** (M10): una sola sesión abierta por establecimiento, cierre con **arqueo de efectivo** y registro de **diferencia con motivo**. La caja es **la compuerta de la venta** (regla global 5: no se vende sin caja abierta), por eso va **antes** de Órdenes (S7) y Pagos (S8). Es el paso 5 de §23 del roadmap.

---

## 0. Punto de partida (lo que ya existía del Sprint 0)

- **Tabla `sesiones_caja`** creada y verificada en PostgreSQL desde el Sprint 0, con **índice único parcial** `uq_sesiones_caja_abierta_parcial ON (id_establecimiento) WHERE estado = 'abierta'` (solo PostgreSQL). Columnas: `monto_inicial`, `monto_sistema`, `monto_contado`, `diferencia` (DECIMAL 12,2), `estado` ENUM(`abierta`,`cerrada`), `abierta_at`/`cerrada_at`, FKs de apertura/cierre.
- **Modelo `SesionCaja`** con relaciones (`usuarioApertura`, `usuarioCierre`, `ordenes`), casts y `BelongsToTenant`; `timestamps = false` (lifecycle por `abierta_at`/`cerrada_at`).
- **Tablas `ordenes` y `pagos`** ya migradas (vacías): sus módulos llegan en S7/S8, pero existen para que las reglas de caja consulten contra ellas desde ahora.
- **Permisos sembrados:** `caja.abrir`, `caja.cerrar` (ADMIN y OPERADOR; ver `RolesPermisosSeeder`).

**Novedad respecto a los Sprints 4–5:** este sprint **sí añade UNA migración** —la columna `motivo` en `sesiones_caja` (D1)—, la **primera migración nueva desde el Sprint 0**. Por tanto, a diferencia de S4/S5, **hay que re-verificar la paridad SQLite↔PostgreSQL** (la columna es un `string` nullable portable, sin índices nuevos).

El Sprint 6 es, salvo esa columna, **capa de aplicación**: servicios de dominio, middleware, controller, Form Requests, Resource, Policy, eventos, excepciones de dominio, factories mínimas y pruebas.

---

## 1. Decisiones de producto ratificadas (con el owner, antes de implementar)

| # | Decisión abierta | Resolución |
|---|---|---|
| D1 | **Dónde se guarda el `motivo` obligatorio cuando `diferencia ≠ 0`** (la tabla no tenía columna). | **Nueva columna `motivo` (nullable) en `sesiones_caja`** vía migración nueva. Queda consultable directo para el **reporte de caja con diferencias** (S11). Obligatorio **solo si `diferencia ≠ 0`** (P5); se valida como regla de negocio en `CerrarCajaService` (la diferencia se calcula en servidor, no en el Form Request). |
| D2 | **Qué cajas ve el OPERADOR en el histórico.** | **Todas las del establecimiento** (admin y operador ven el mismo histórico): la caja es **del establecimiento y compartida por el turno**. El acotamiento por turno del operador (P21) se difiere a los **reportes (S11)**, como prevé el roadmap. |

---

## 2. Módulos y endpoints (cadena `auth:sanctum → resolve.tenant → tenant.activo`)

| Módulo | Endpoints |
|---|---|
| **M10 · Caja** | `POST /api/v1/caja/abrir`, `POST /api/v1/caja/cerrar`, `GET /api/v1/caja/actual`, `GET /api/v1/caja/historico` |

**Servicios** (`app/Domain/Caja/Services`, transaccionales y auditados §15):
- `AbrirCajaService` — `lockForUpdate` sobre el establecimiento; verifica que **no exista** sesión `abierta`; crea la sesión (`monto_inicial`, `id_usuario_apertura`, `abierta_at`); emite `CajaAbierta`.
- `CerrarCajaService` — verifica **ausencia de órdenes abiertas** en la sesión; calcula `monto_sistema = monto_inicial + Σ pagos en efectivo` (P6/P7); calcula `diferencia = monto_contado − monto_sistema`; **exige `motivo` si `diferencia ≠ 0`** (P5); **sin autorización**; fija `estado=cerrada`, `id_usuario_cierre`, `cerrada_at`; emite `CajaCerrada`.

**Middleware:** `EnsureCajaAbierta` — bloquea la venta si no hay caja abierta (regla global 5). Se **crea y prueba** en este sprint; su **aplicación a las rutas de venta llega en S7** (aún no hay rutas de órdenes). Se registra el alias en `bootstrap/app.php`.

**Policy:** `SesionCajaPolicy` — `abrir`/`cerrar` para ADMIN y OPERADOR (permisos sembrados); SUPER_ADMIN **no** opera caja de un tenant. `viewAny`/`view` para ambos roles (D2).

**Eventos:** `CajaAbierta`, `CajaCerrada` (consumidos por auditoría/reportes; emitidos dentro de la transacción).

**Form Requests / Resource:** `AbrirCajaRequest` (`monto_inicial ≥ 0`), `CerrarCajaRequest` (`monto_contado ≥ 0`, `motivo` opcional en forma; obligatoriedad real por diferencia en el servicio), `SesionCajaResource`.

**Excepciones de dominio:** `CajaYaAbiertaException` (409), `CajaConOrdenesAbiertasException` (409), `MotivoDiferenciaRequeridoException` (422).

---

## 3. Contratos clave

- **Una sola caja abierta por establecimiento.** `AbrirCajaService` usa `lockForUpdate` sobre el establecimiento + verificación de no-existencia; el respaldo en BD es el **índice único parcial (solo PostgreSQL)**. En SQLite ese índice parcial **no se crea** (la migración lo emite solo en pgsql), por lo que la garantía recae en el **bloqueo pesimista + verificación**; el test de concurrencia asienta el **409 en ambos motores** a nivel de aplicación, y el índice parcial es la última línea en producción.
- **Arqueo solo efectivo (P6).** `monto_sistema = monto_inicial + Σ pagos en efectivo` de las órdenes de la sesión (`pagos ⋈ ordenes ⋈ tipos_pago` donde el tipo es *efectivo*). Tarjeta/transferencia **no** suman al arqueo (van solo a reporte). **Sin retiros/fondos (P7):** no hay términos de salida. Como `pagos` está vacío en S6, **Σ = 0** y `monto_sistema = monto_inicial`; **la consulta queda cableada y correcta** para cuando Pagos (S8) la alimente.
- **Diferencia y motivo.** `diferencia = monto_contado − monto_sistema`; si `diferencia ≠ 0` el `motivo` es **obligatorio** (P5). El cierre **no requiere autorización** en este sprint (la autorización de diferencias queda diferida).
- **No cerrar con órdenes abiertas.** `CerrarCajaService` verifica que no existan órdenes `estado=abierta` en la sesión; la tabla `ordenes` existe (vacía ahora), de modo que la **regla queda activa y será válida sin cambios** cuando S7 genere órdenes.
- **Caja cerrada no se reabre:** `estado=cerrada` es terminal; reabrir → error.
- **`GET /caja/actual`** responde **200 con la sesión abierta**, o **200 con `data: null`** si no hay caja abierta (el front consulta el estado sin tratar la ausencia como error). **`GET /caja/historico`** pagina las sesiones del establecimiento, orden descendente por `abierta_at`.
- **Auditoría §15:** `caja.abierta` y `caja.cerrada` se registran **dentro de la transacción** del servicio (patrón S4/S5; la consolidación por Observer es del S12).

---

## 4. Decisiones de contrato menores

- **Primera migración nueva desde S0** (D1): columna `motivo` (`string` nullable) en `sesiones_caja`. Sin índices nuevos; portable a SQLite y PostgreSQL ⇒ se re-corre la suite en **ambos** motores para confirmar la paridad.
- **`monto_inicial ≥ 0`** y **`monto_contado ≥ 0`** en los Form Requests; la diferencia puede ser negativa (faltante) o positiva (sobrante).
- **Factories mínimas de `Orden` y `Pago`** (+ `HasFactory` donde falte), añadidas **solo** para poder sembrar pagos en efectivo/no-efectivo y ejercitar el cálculo del arqueo (P6). Es una huella transversal análoga a la del S5 (factories de inventario); no adelanta lógica de Órdenes/Pagos.
- **SUPER_ADMIN** no abre ni cierra caja (no opera el tenant); ADMIN y OPERADOR sí, según la matriz Fase 7.

---

## 5. Pruebas (DoD §4 / §509–512)

- **Unit** (`tests/Unit/Caja`):
  - `AbrirCajaServiceTest` — abre con `monto_inicial`; **rechaza una segunda apertura** (`CajaYaAbiertaException`).
  - `CerrarCajaServiceTest` — `monto_sistema` suma **solo efectivo** (P6/P7; se siembran pagos efectivo + tarjeta y se verifica que la tarjeta no entra); **exige motivo si `diferencia ≠ 0`** (P5); **`diferencia = 0` cierra sin motivo**; **bloquea cierre con órdenes abiertas** (`CajaConOrdenesAbiertasException`).
- **Feature** (`tests/Feature/Caja`):
  - `CajaTest` — abrir (ADMIN y OPERADOR); `GET /caja/actual` refleja abierta y `null` sin caja; cerrar con `diferencia = 0` y con `diferencia ≠ 0 + motivo`; **cerrar con `diferencia ≠ 0` sin motivo → 422**; **caja cerrada no se reabre**; **segunda apertura → 409**; histórico lista cerradas + abierta (D2).
  - `EnsureCajaAbiertaTest` — una ruta de prueba protegida responde bloqueo **sin caja** y pasa **con caja**.
- **Integration / Concurrencia** (`tests/Integration/Caja`):
  - `CajaConcurrenciaTest` — **doble apertura simultánea falla** (bloqueo de aplicación en ambos motores + índice parcial en pgsql); **aislamiento entre tenants** (la caja de A no es visible ni accesible para B).
- **Estado esperado:** suite **verde en SQLite y PostgreSQL 17**, **Pint limpio**. Se parte de **105** pruebas (cierre del S5).

---

## 6. Fuera de alcance (sprints siguientes)

- **Aplicar `EnsureCajaAbierta` a las rutas de venta** — **Sprint 7** (Órdenes).
- **Σ pagos en efectivo con datos reales** — **Sprint 8** (Pagos los genera; aquí la consulta queda cableada con resultado 0).
- **Autorización de diferencias de caja** — diferida (Fase 9 / posterior); el roadmap fija "sin autorización" en S6.
- **Acotamiento del histórico por turno del operador (P21)** — **Sprint 11** (Reportes).
- **Reporte de caja** (aperturas/cierres/diferencias) — **Sprint 11**.
- **Retiros/fondos de caja** — fuera de V1 (P7).
- **Congelar órdenes al desactivar establecimiento con caja abierta (P19)** — el contrato se respeta; el flujo completo depende de Órdenes (S7).

## 7. Deuda heredada (sin cambios)

Decisión formal de RLS, divergencia de timestamps (R4), unificación del patrón de auditoría (Observer en S12). Factories: este sprint añade las mínimas de `Orden`/`Pago` que el test de arqueo requiere.
