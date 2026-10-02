# SPRINT_1_REVIEW.md — Revisión Arquitectónica del Sprint 1

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Revisor:** Arquitecto de Software Senior
**Fecha:** 2026-06-15
**Base de evaluación:** `RoadmapImplementacion.md` (Sprint 1, §4 Pruebas, §5 DoD), `SPRINT_0_REVIEW.md` (condiciones P0 heredadas), `ArquitecturaBackend.md` (§7–§10, §15, §20), `Convenciones.md` (§2, §4).
**Objeto:** validar el **Sprint 1 — Autenticación, tenancy y autorización (+ auditoría base)** y el código adicional efectivamente entregado bajo esa etiqueta.

---

## 0. Encuadre de alcance (qué exigía el Sprint 1 y qué se entregó)

El Roadmap define el Sprint 1 como **M01 Autenticación + infraestructura de M15 Auditoría (trait + listener + servicio)**, con los endpoints `auth/login`, `auth/logout`, `auth/recuperar`, `auth/me`, los middleware `ResolveTenant`/`EnsureTenantActivo`, el `Gate::before` para super_admin y la reconciliación roles DER↔Spatie.

**Hallazgo de encuadre.** La entrega rotulada como "Sprint 1" (ver `routes/api.php:10`) abarca, de hecho, **tres sprints del roadmap**:

- **M01 Autenticación** (Sprint 1 oficial) — implementado.
- **M02 Plataforma/Establecimientos** (Sprint 2 oficial) — implementado **parcialmente** (sin M03 Configuración).
- **M04 Usuarios/Roles** (Sprint 3 oficial) — implementado.

Esto se evalúa en dos planos: (a) el **Sprint 1 oficial** está cubierto y excedido en su núcleo funcional; (b) el **adelanto de M02/M04** está bien construido pero introduce un desajuste de trazabilidad con el roadmap y deja **M03 y `auth/recuperar` sin cerrar** (ver §3). El comentario en `ActualizarEstablecimientoService.php:11` ("M03, fuera del alcance del Sprint 1") confirma que la omisión de configuración es deliberada, pero **no está ratificada** en el roadmap.

---

## 1. Verificación punto por punto

Leyenda: ✅ Correcto · ⚠️ Correcto con observación/riesgo · ⛔ Defecto/Pendiente bloqueante · ⏭️ Diferido

| # | Área | Estado | Evidencia |
|---|------|--------|-----------|
| 1 | **Login / logout / me (Sanctum)** | ✅ | `AuthController` + `AutenticarUsuarioService`. Token Bearer, revocación en logout (`AuthController.php:37`), `me` expone permisos + `es_super_admin`. |
| 2 | **Middleware `ResolveTenant`** | ✅ | Fija `TenantContext` y `team_id` de Spatie tras autenticar; super_admin sin contexto salvo impersonación. `ResolveTenant.php:27`. |
| 3 | **Middleware `EnsureTenantActivo` (P19)** | ✅ | Establecimiento desactivado → 423; super_admin/impersonación pasan. `EnsureTenantActivo.php:21`. |
| 4 | **`Gate::before` super_admin** | ✅ | `AppServiceProvider.php:27` concede todo al super_admin; verificado indirectamente por los tests de plataforma. |
| 5 | **Reconciliación roles DER↔Spatie + teams** | ✅ | `ProveedorRolesTenant` centraliza `team_id = id_establecimiento`; `id_rol` como cache; guard estable vía `Guard::getDefaultName` (evita el salto a `sanctum` de `actingAs`). `ProveedorRolesTenant.php:25`. |
| 6 | **Auditoría base (servicio + subscriber síncrono + trait)** | ✅ | `RegistrarAuditoriaService` escribe dentro de la transacción de negocio (§15); `RegistrarAuditoria` es subscriber **síncrono** a propósito; `Auditable` filtra campos sensibles. |
| 7 | **Compensación P18 (revocación de tokens)** | ✅ | Al desactivar usuario (`CambiarEstadoUsuarioService.php:29`) y al desactivar establecimiento (`CambiarEstadoEstablecimientoService.php:25`) se borran sus tokens. **Cierra la R5 del Sprint 0.** |
| 8 | **Impersonación P17** | ⚠️ | Marcada (`TenantContext::impersonando`) y auditada en `ResolveTenant.php:46`. Observación: se audita **en cada petición** con la cabecera, fuera de transacción → ruido de auditoría; conviene auditar el inicio de la sesión de soporte, no cada request. |
| 9 | **Excepciones de dominio → envoltura JSON** | ✅ | `DomainException` abstracta con `statusHttp()`; `bootstrap/app.php:38` traduce dominio/validación/auth/404 a `ApiResponse`. Códigos 401/403/409/422/423 coherentes. |
| 10 | **Policies (Establecimiento, Usuario)** | ✅ | Mapeadas a `can('permiso')`; reglas de estado (último admin) viven en servicio, no en policy (DoD §5.4). |
| 11 | **Regla del último ADMIN activo** | ✅ | `UltimoAdminGuard` invocado antes de desactivar y de degradar rol; test en verde (409). |
| 12 | **Unicidad email/username por tenant** | ✅ | `CrearUsuarioRequest.php:29` con `where(id_establecimiento)` + `whereNull(deleted_at)`; tomado del `TenantContext`, nunca del cliente. |
| 13 | **API Resources / paginación** | ✅ | `UsuarioResource` no expone `password_hash`; `ApiResponse::coleccion` con meta/links; `perPage` acotado 1–100. |
| 14 | **Atomicidad de servicios de escritura** | ✅ | Todos los servicios envuelven en `DB::transaction`; eventos despachados dentro de la transacción (auditoría consistente). |
| 15 | **Endpoint `auth/recuperar`** | ⛔ | **No implementado.** Es entregable explícito del Sprint 1 (Roadmap §2, línea de endpoints). No existe ruta, controlador ni servicio. |
| 16 | **Validación contra PostgreSQL (P0 heredado)** | ⛔ | **No resuelto.** `pdo_pgsql` sigue ausente; las pruebas corren en **SQLite `:memory:`** (`phpunit.xml`) mientras `.env` apunta a `pgsql`. Era la **condición explícita** de aprobación del Sprint 0. |
| 17 | **M03 Configuración (si se reclama Sprint 2)** | ⏭️/⛔ | Sin endpoints `GET/PUT /configuracion`, sin `ActualizarConfiguracionService`. Diferido de hecho, no ratificado. |
| 18 | **Tests de auditoría / impersonación (DoD §4)** | ⛔ | Ningún test asserta filas en `auditoria` ni el marcado de impersonación, pese a ser prueba de integración exigida para el Sprint 1. |

**Pruebas ejecutadas:** `php artisan test` → **24 passed (54 assertions)**. **Pint** → `passed`. (Toda la suite corre sobre SQLite en memoria; ver #16.)

---

## 2. Qué quedó correcto

1. **Núcleo de autenticación sólido y probado.** Login por email/username, logout que revoca el token, `me` con permisos y bandera de super_admin. Cubre los casos válido/invalido/usuario inactivo (403)/establecimiento inactivo (423)/logout/no autenticado (401).
2. **Cadena de middleware idiomática y correcta.** `auth:sanctum → resolve.tenant → tenant.activo`, con la ruta de login fuera de la cadena (pública). El `TenantContext` y el `team_id` de Spatie se fijan de forma consistente.
3. **Autorización del super_admin resuelta** vía `Gate::before` sobre el invariante `id_establecimiento = null`, sin forzar pivotes Spatie con team nulo.
4. **Auditoría base fiel a §15:** escritura **dentro de la misma transacción** del hecho de negocio, mediante subscriber síncrono y servicio dedicado; trait que excluye `password_hash`/`remember_token`.
5. **Reconciliación roles↔Spatie bien encapsulada** en `ProveedorRolesTenant`, con manejo explícito y reentrante del `team_id` (guarda/restaura el team previo) y resolución de guard robusta ante `Sanctum::actingAs`.
6. **Compensación de P18 implementada** (revocación de tokens al desactivar usuario o establecimiento): cierra directamente la R5 que el Sprint 0 dejó abierta.
7. **Contrato de errores homogéneo** (envoltura `success/message/data`, códigos HTTP correctos por excepción de dominio) y serialización por Resources sin fuga de columnas internas.
8. **Reglas de negocio en la capa correcta:** último admin y unicidad por tenant viven en servicios/requests, no en policies; las policies sólo mapean permisos.
9. **Aislamiento multi-tenant verificado a nivel de petición:** un admin de A recibe 404 al consultar un usuario de B (`UsuarioTest::test_aislamiento...`).

---

## 3. Qué quedó incompleto

### Pendiente real del Sprint 1 (bloquea el cierre)
- **`POST /api/v1/auth/recuperar`** — entregable explícito del Sprint 1, **ausente** (no hay ruta/controlador/servicio/notificación de correo).
- **Validación contra PostgreSQL (P0 heredado del Sprint 0)** — **no ejecutada**. El esquema y los índices únicos parciales siguen sin correr en el motor objetivo; el entorno ni siquiera puede `migrate` contra pg (sin `pdo_pgsql`). Era la condición de aprobación del Sprint 0.
- **Pruebas de integración de auditoría e impersonación (DoD §4)** — no existen; la auditoría se ejercita sólo de forma indirecta y nunca se asserta.

### Diferido de hecho, sin ratificar (afecta al Sprint 2)
- **M03 Configuración del Establecimiento** — sin endpoints `GET/PUT /configuracion` ni `ActualizarConfiguracionService`. La siembra de `configuracion_establecimiento` por defecto sí existe (`CrearEstablecimientoService.php:42`), pero no su edición.
- **Política de contraseñas P16 "configurable"** — implementada como `Password::min(8)` *hardcodeada* (`CrearUsuarioRequest.php:35`, `CrearEstablecimientoRequest.php:37`); el roadmap la describe como configurable.

### Deuda heredada del Sprint 0 aún abierta
- Decisión formal de **RLS** (Arq §7) — sin documentar.
- **Factories 4/22** — sin avanzar.
- **CI contra PostgreSQL** — inexistente.
- **Divergencia de timestamps (R4)** — sin ratificar ni revertir.

---

## 4. Riesgos

| ID | Riesgo | Severidad | Impacto |
|----|--------|-----------|---------|
| R1 | **El esquema nunca se ha ejecutado en PostgreSQL.** Toda la suite valida en SQLite `:memory:`; los índices únicos parciales (caja/orden por tenant) se omiten por driver. `.env` apunta a pgsql sin `pdo_pgsql` instalado. | **Alta** | Un error de DDL/índice no se detecta hasta el primer `migrate` real; el respaldo en BD de reglas críticas (Arq §19) sigue sin verificarse. Condición de aprobación del Sprint 0 incumplida. |
| R2 | **Desajuste de trazabilidad de sprints.** Se fusionaron M01+M02+M04 bajo "Sprint 1" sin actualizar el roadmap; M03 quedó silenciosamente fuera. | **Media** | DoD por sprint deja de ser verificable; se pierde el control de qué módulo está realmente "terminado". |
| R3 | **Cobertura de auditoría no probada.** Pese a ser el corazón de §15, ningún test asegura que se escriba `auditoria` ni que la impersonación quede marcada. | **Media** | Una regresión que rompa la auditoría financiera pasaría inadvertida en el sprint que más la necesita (caja/pagos). |
| R4 | **Login: colisión de credenciales entre tenants.** `AutenticarUsuarioService.php:27` trae todas las coincidencias por email/username y prueba `Hash::check` en cada una. | Media | Carga O(n) de hashing y, ante credenciales repetidas en dos tenants, resolución no determinista. Documentado como supuesto MVP, pero frágil. |
| R5 | **Enumeración de cuentas por código de estado.** Login devuelve 401 (credencial), 403 (inactivo), 423 (establecimiento) diferenciados. | Baja | Permite distinguir si un usuario existe/está inactivo. Aceptable para MVP interno; revisar antes de exponer públicamente. |
| R6 | **Auditoría de impersonación por-request y fuera de transacción.** `ResolveTenant.php:46`. | Baja | Ruido en `auditoria`; coste de escritura en cada petición de soporte. |
| R7 | **Patrón de auditoría mixto.** Unos servicios emiten eventos (Crear/CambiarEstado usuario) y otros llaman directo al servicio (AsignarAdmin, CambiarEstado establecimiento). | Baja | Inconsistencia de mantenimiento; dos caminos para el mismo objetivo. |
| R8 | **Invariante super_admin ⇔ tenant nulo** sigue sin protección a nivel de BD (heredado R3 Sprint 0). | Baja | Si se violara, escalada de privilegios vía `Gate::before`. |

---

## 5. Qué debe corregirse antes de Sprint 2

**Bloqueantes (deben cerrarse para aprobar el paso a Sprint 2):**

1. **Resolver el P0 heredado:** habilitar `pdo_pgsql`, ejecutar `php artisan migrate:fresh --seed` contra **PostgreSQL real** y confirmar (a) migraciones reversibles, (b) creación de los índices únicos parciales, (c) seeders idempotentes. Configurar el **CI contra pg**. *(Era la condición explícita de aprobación del Sprint 0; no puede arrastrarse un sprint más.)*
2. **Implementar `POST /api/v1/auth/recuperar`** (recuperación por correo) **o** descopearlo formalmente con firma del owner y actualización del roadmap. Hoy es un entregable de Sprint 1 ausente y no ratificado.
3. **Añadir las pruebas de integración del DoD §4 del Sprint 1:** que `RegistrarAuditoria` escribe en `auditoria` dentro de la transacción, y que la impersonación del super_admin queda marcada y auditada.
4. **Reconciliar la trazabilidad de sprints:** actualizar el roadmap para reflejar que el "Sprint 1" entregado = M01 + M02(establecimientos) + M04, y **decidir explícitamente** el destino de **M03 Configuración** (cerrarlo ahora como parte de M02 o diferirlo con ratificación).

**Recomendados (cerrar para no arrastrar deuda):**

5. Hacer **configurable la política de contraseñas (P16)** en lugar de `Password::min(8)` literal.
6. Mover la **auditoría de impersonación** al inicio de la sesión de soporte (no por request) y, si procede, dentro de transacción.
7. Unificar el **patrón de auditoría** (evento → subscriber) en todos los servicios para una sola vía.
8. Endurecer el **login** (índice/filtrado por tenant cuando se conozca, o limitar el fan-out de `Hash::check`) y evaluar homogeneizar la respuesta de credenciales para mitigar enumeración (R5).

**Heredados del Sprint 0 (gestionar como deuda planificada):** decisión de RLS, factories 4/22, divergencia de timestamps (R4), invariante super_admin a nivel de BD.

---

## 6. Conclusión

El **núcleo funcional del Sprint 1 oficial** —autenticación Sanctum, resolución de tenant, autorización del super_admin, reconciliación roles↔Spatie con teams y la infraestructura de auditoría transaccional— está **bien implementado, idiomático y en verde** (24 tests, Pint limpio), y además **cierra la R5 del Sprint 0** (compensación de P18). El adelanto de M02/M04 es de buena factura.

Sin embargo, persisten **tres incumplimientos bloqueantes**: (1) el **P0 de validación contra PostgreSQL**, que fue la *condición explícita* bajo la cual se aprobó el Sprint 0 y que **sigue sin ejecutarse** —el esquema nunca ha corrido en el motor objetivo—; (2) el endpoint **`auth/recuperar` ausente** sin descopeo ratificado; y (3) la **falta de las pruebas de auditoría/impersonación** que el propio DoD del Sprint 1 exige. A ello se suma un **desajuste de trazabilidad** (tres sprints del roadmap fusionados, con M03 fuera sin ratificar).

Los puntos 1–4 no son defectos de diseño del código entregado, pero son **garantías de calidad y de proceso** que el roadmap define como parte del *Definition of Done* del sprint y cuyo arrastre compromete el camino crítico posterior (caja/órdenes/pagos dependen de que el esquema y la auditoría estén verificados en pg). No corresponde abrir el Sprint 2 sobre una base cuyo esquema no se ha probado en el motor real por segundo sprint consecutivo.

---

# Dictamen

## NO APROBADO PARA SPRINT 2

**Motivo:** el código funcional es sólido, pero quedan **bloqueantes del *Definition of Done*** sin cerrar — en particular el **P0 de PostgreSQL heredado del Sprint 0** (condición de su aprobación, aún incumplida), el endpoint **`auth/recuperar`** ausente y las **pruebas de auditoría/impersonación** faltantes.

**Camino a la aprobación:** cerrados los puntos **1–4** de la Sección 5 (validación contra PostgreSQL + CI, `auth/recuperar` o su descopeo ratificado, pruebas de auditoría/impersonación, y reconciliación de la trazabilidad de sprints con decisión sobre M03), la entrega quedaría **APROBADA PARA SPRINT 2**. Los puntos 5–8 y la deuda heredada pueden gestionarse dentro del Sprint 2 sin bloquearlo.

---

# ADDENDUM — Cierre de bloqueantes (2026-06-15)

Tras el dictamen anterior se ejecutó la remediación de los cuatro bloqueantes. Verificación realizada:

### 1. PostgreSQL real ✅
- Se habilitó `pdo_pgsql`/`pgsql` en el entorno y se levantó **PostgreSQL 17** real para verificación.
- `php artisan migrate --force`: **26 migraciones** ejecutadas sin error en el motor objetivo.
- `php artisan migrate:rollback --force`: **reversibilidad total** (solo queda la tabla `migrations`).
- `php artisan migrate:fresh --seed --force`: esquema + seeders en verde.
- **Índices únicos parciales creados (3):** `uq_sesiones_caja_abierta_parcial`, `uq_ordenes_abierta_mesa_parcial`, `idx_insumos_stock_bajo_parcial`. Se **probó el enforcement**: un segundo `INSERT` de sesión `abierta` para el mismo establecimiento es rechazado por la unicidad parcial → **R1 del Sprint 0 cerrada definitivamente**.
- **Seeders idempotentes** en pg (conteos estables tras re-siembra: tipos_orden=3, tipos_pago=3, unidades_globales=10, roles=3, permisos=32, super_admin=1).
- **Factories** (4/22) compatibles con pg (la suite las usa y pasa).
- **CI:** `.github/workflows/ci.yml` con servicio `postgres:16`, `pdo_pgsql`, migraciones (up + rollback + fresh/seed), Pint y la suite contra pg.
- **Pruebas sobre pg:** `php artisan test -c phpunit.pgsql.xml` → **32/32 verde**.

### 2. `POST /api/v1/auth/recuperar` ✅
Implementado completo: `RecuperarPasswordRequest`, `RecuperarPasswordService` (password broker, solo usuarios activos), `RecuperarPasswordNotification` (correo en español con enlace al frontend), ruta pública, override `Usuario::sendPasswordResetNotification`. Respuesta **genérica anti-enumeración**. 4 pruebas Feature en verde. *(El endpoint de reset con token no es de Sprint 1 según el roadmap; queda fuera y documentado.)*

### 3. Pruebas de integración de auditoría ✅
`tests/Integration/Auditoria/RegistrarAuditoriaTest.php`: (a) auditoría al crear establecimiento vía evento; (b) auditoría al crear usuario vía evento; (c) **consistencia transaccional** (rollback ⇒ sin fila; commit ⇒ con fila); (d) **impersonación del super_admin auditada** (`soporte.impersonacion`). Suite `Integration` registrada en `phpunit.xml` y `phpunit.pgsql.xml`.

### 4. Trazabilidad ✅
Nuevo `docs/SPRINT_1_ALCANCE.md` con módulos implementados (M01, M15-base, M02 establecimientos, M04), pendientes (**M03 Configuración** — única pieza restante de la capa 1) y alcance efectivo. El Sprint 2 debe **arrancar cerrando M03**.

### Estado final
- **Suite:** 32/32 verde en **SQLite** y en **PostgreSQL**; **Pint** limpio.
- Deuda no bloqueante (factories 18/22 restantes, RLS, timestamps R4, P16 configurable) queda registrada para gestión dentro del Sprint 2.

---

# Dictamen actualizado (post-remediación)

## APROBADO PARA SPRINT 2

Los cuatro bloqueantes están cerrados y verificados contra el motor objetivo (PostgreSQL). Se aprueba el avance, con la indicación de que el Sprint 2 **inicie cerrando M03 Configuración** (edición) para completar la capa 1 antes de abordar los catálogos de venta.
