# SPRINT_12_REVIEW.md — Revisión Arquitectónica del Sprint 12 (cierre del MVP V1)

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Revisor:** Arquitecto de Software Senior
**Fecha:** 2026-07-01
**Base de evaluación:** `RoadmapImplementacion.md` (Sprint 12, §4 Pruebas §539–542, §5 DoD, §561 compuerta de cierre), `SPRINT_12_ALCANCE.md`, `EspecificacionFuncional.md` (M15, matriz Fase 7, P14/P16/P17/P20), `ArquitecturaBackend.md` (§7, §9, §13, §15), `Convenciones.md`, `DatabaseDictionary.md` (`auditoria`).
**Objeto:** validar el **Sprint 12 — Consolidación de auditoría y endurecimiento (M15)**: cobertura de auditoría, aislamiento multi-tenant, concurrencia, matriz de permisos completa, y confirmación de P16/P20 antes de congelar el contrato de API del MVP V1.

---

## 0. Encuadre de alcance (qué exigía el Sprint 12 y qué se entregó)

El Roadmap define el Sprint 12 como el **cierre transversal**: auditoría CRUD por Observer, auditoría de impersonación (P17) y reimpresión (P14), y la batería de pruebas de aislamiento, concurrencia y matriz de permisos, más la verificación de P16 y P20 (pasos 9 y 11 de §23 en su faceta de verificación).

**Hallazgo de encuadre (decisión estructural del sprint).** Al auditar el estado real del sistema se constató que **la cobertura de auditoría ya estaba construida**, sprint a sprint, por la vía **inline en cada servicio** (el DoD #5 de cada sprint la exigía): todo el CRUD de maestros (`producto.*`, `categoria.*`, `mesa.*`, `impresora.*`, `insumo.*`, `proveedor.*`, `unidad_medida.*`, `receta.*`, `configuracion.actualizada`, `establecimiento.*`, `usuario.*`), todas las acciones de negocio (caja, órdenes, pagos, inventario, autorizaciones), la **impersonación P17** (`soporte.impersonacion` en `ResolveTenant`) y la **reimpresión P14** (`ticket.reimpreso`, S10). En consecuencia:

- **D1 (ratificada):** **no se introduce el Observer.** Cablearlo sobre los mismos modelos **duplicaría** cada registro (`producto.creado` del servicio + `producto.created` del Observer). La auditoría inline es además **superior en consistencia transaccional** (§15): se escribe dentro de la `DB::transaction` del servicio, no en un hook `saved`/`deleted` que puede quedar fuera. El Sprint 12 **verifica** la cobertura en lugar de reconstruirla.
- **D4 (ratificada):** el sprint **no añade código de producción** —ni endpoints, ni servicios, ni migraciones, ni seeders—; entrega **pruebas y documentación**. Es un sprint de endurecimiento puro, coherente con "Endpoints: ninguno nuevo" del roadmap.

Esta desviación respecto de la letra del roadmap (auditoría inline en vez de por Observer) es **deliberada, documentada y de menor riesgo**; el objetivo del roadmap —cobertura de auditoría completa y transaccional— se cumple.

---

## 1. Verificación punto por punto

Leyenda: ✅ Correcto · ⚠️ Correcto con observación/riesgo · ⛔ Defecto/Pendiente · ⏭️ Diferido por diseño

| # | Área | Estado | Evidencia |
|---|------|--------|-----------|
| 1 | **Matriz de permisos — operador vs solo-admin** | ✅ | `MatrizPermisosTest`: operador → 403 en reportes de gestión, auditoría, catálogo (`POST /categorias`) y personal (`POST /usuarios`). |
| 2 | **Matriz — operaciones bloqueadas 🔐** | ✅ | Operador → 403 directo en `anular`, `cancelar_item`, `POST /movimientos` (entrada/ajuste): no posee el permiso directo, solo puede solicitar (S9). |
| 3 | **Matriz — operador opera lo suyo** | ✅ | Operador → 200/201 en dashboard, `caja/actual`, `ordenes`, `POST /movimientos` (merma). |
| 4 | **Matriz — admin vs plataforma** | ✅ | Admin → 403 en `establecimientos` y `auditoria/global`; 200/201 en `auditoria`, `reportes/inventario`, `POST /categorias`. |
| 5 | **Matriz — super_admin** | ✅ | `Gate::before` concede plataforma y global; super_admin → 200 en `establecimientos` y `auditoria/global`. |
| 6 | **Auditoría CRUD de maestro** | ✅ | `CoberturaAuditoriaTest`: `POST /categorias` deja `categoria.creada` con `id_establecimiento`. |
| 7 | **Auditoría de acción de negocio** | ✅ | Cobro deja `orden.pagada` con `entidad_id` de la orden. |
| 8 | **Impersonación auditada (P17)** | ✅ | Super_admin con `X-Establecimiento-Id` → `soporte.impersonacion` sobre `establecimientos`. |
| 9 | **Reimpresión auditada (P14)** | ✅ | Reimprimir un ticket deja `ticket.reimpreso` (además del cubrimiento previo en S10). |
| 10 | **Consistencia transaccional (§15)** | ✅ | Una aprobación de autorización que falla (orden ya pagada) **no** deja `autorizacion.aprobada` y la solicitud sigue `pendiente`: la auditoría revierte con la acción. |
| 11 | **Diff sin secretos (§540)** | ✅ | Unit `AuditableTest`: `filtrarParaAuditoria` excluye `password_hash`/`remember_token`/`updated_at` y conserva el resto. |
| 12 | **Aislamiento multi-tenant (API)** | ✅ | `AislamientoApiTest`: actor de B → 404 en orden, ticket, insumo, kardex y producto de A. |
| 13 | **Concurrencia** | ✅ | Reutilizadas en verde: `CajaConcurrenciaTest` (doble apertura falla) y `OrdenConcurrenciaTest` (doble orden por mesa falla) por índice único parcial + bloqueo. |
| 14 | **P16 (contraseñas)** | ✅ | `PoliticaPasswordTest` (S1): mínimo 8 configurable, sin bloqueo por intentos. |
| 15 | **P20 (costo del margen)** | ✅ | `ReporteGestionTest` (S11): costo por `controla_inventario` (referencia vs receta). |
| 16 | **Sin código nuevo / paridad** | ✅ | Sin migraciones, servicios, endpoints ni seeders nuevos; suite verde en SQLite y PostgreSQL 17. |
| 17 | **Observer de auditoría (roadmap §540)** | ⏭️ | Sustituido por la auditoría inline ya existente (D1); introducirlo duplicaría registros. Desviación documentada. |

**Pruebas ejecutadas en esta revisión:** `php artisan test` → **242 passed (614 assertions)** en SQLite; `php artisan test -c phpunit.pgsql.xml` → **242 passed** en **PostgreSQL 17**; `pint --test` → `passed`. El Sprint 12 añade **13 pruebas** (1 Unit, 6 Feature, 6 Integration) sobre las 229 del cierre del S11, sin tocar código de producción.

---

## 2. Qué quedó correcto

1. **La matriz de permisos verifica los límites de seguridad reales.** No es un catálogo cosmético: comprueba que el operador es rechazado en las operaciones de solo-admin y en las cuatro 🔐 (que solo puede solicitar), que el admin no cruza a plataforma, y que el super_admin pasa por `Gate::before`. Los tres roles quedan acotados por comportamiento observable.
2. **La cobertura de auditoría se demostró end-to-end.** CRUD de maestro, acción de negocio, impersonación (P17) y reimpresión (P14) dejan su rastro; y —lo más importante— una acción que **falla no deja auditoría**, confirmando que la bitácora vive en la transacción de su acción (§15).
3. **La decisión de no introducir el Observer es la correcta.** Evita duplicar cada registro y preserva la garantía transaccional que un hook de modelo no ofrece. La auditoría inline, exigida por el DoD de cada sprint, resultó ser la consolidación misma.
4. **El aislamiento se probó a través del API, no solo del scope.** Un actor de B recibe 404 —no 403 ni datos vacíos— en los recursos de A, que es el contrato correcto del `TenantScope`.
5. **El sprint no añadió superficie.** Cero código de producción, cero migraciones: el endurecimiento es verificación, no construcción, lo que reduce el riesgo justo antes de congelar el contrato.

---

## 3. Qué quedó incompleto

### Diferido por diseño (no bloquea el cierre del MVP)
- **Observer de auditoría CRUD** — sustituido por la auditoría inline (D1).
- **Row-Level Security (RLS) de PostgreSQL** — decisión de infraestructura pendiente desde S0; el aislamiento por `TenantScope` está probado. Se difiere a post-MVP.
- **Aritmética monetaria en decimal** — hoy float en presentación; los importes se congelan en la orden.

### Observaciones a gestionar (no bloqueantes)
- **Desviación documentada del roadmap** (auditoría inline vs Observer): registrada en el ALCANCE y aquí.
- **`ext-gd`/`ext-zip`** siguen siendo dependencia de entorno para la exportación (heredado del S11, R en su review).

---

## 4. Riesgos

| ID | Riesgo | Severidad | Impacto |
|----|--------|-----------|---------|
| R1 | **La cobertura de auditoría depende de la disciplina inline.** Un servicio futuro que olvide llamar a `RegistrarAuditoriaService` no auditaría (un Observer lo cubriría por estructura). | Baja | Hoy la cobertura es completa y probada; el riesgo es de mantenimiento futuro. Mitigación posible post-MVP: un Observer **defensivo** solo para modelos sin auditoría inline, o una prueba de contrato por acción sensible. |
| R2 | **RLS no activado.** El aislamiento se apoya solo en el `TenantScope` de Eloquent; una consulta cruda (`DB::` sin scope) podría saltarlo. | Media | Ninguna ruta del MVP usa consultas crudas sin filtrar por tenant; los reportes y la auditoría acotan explícitamente. RLS sería la defensa en profundidad. Decisión formal pendiente desde S0. |
| R3 | **Aritmética monetaria en float** en agregados y presentación. | Baja | Los importes se congelan en la orden; criterio común S5–S11. |
| R4 | **Dependencia de entorno `ext-gd`/`ext-zip`** para la exportación Excel. | Media | Heredado del S11; debe documentarse en el setup para CI/otros entornos. No afecta al núcleo ni a los reportes interactivos. |

---

## 5. Qué debe corregirse antes de congelar el contrato (§561)

**Bloqueantes:** ninguno. El Sprint 12 cumple su DoD —matriz de permisos completa, cobertura de auditoría (CRUD + negocio + impersonación + reimpresión), consistencia transaccional, aislamiento y concurrencia verificados, P16/P20 confirmadas— sin defectos abiertos ni P0.

**Recomendados (post-MVP, no bloquean el congelamiento):**
1. **Decisión formal sobre RLS** (R2) como defensa en profundidad del aislamiento.
2. **Documentar `ext-gd`/`ext-zip`** en el setup (R4).
3. **Evaluar un Observer defensivo o pruebas de contrato de auditoría** (R1) para blindar el mantenimiento futuro.

**Heredados (deuda planificada explícita):** RLS, aritmética monetaria en float.

---

## 6. Conclusión

El **Sprint 12 está bien resuelto, es honesto y está en verde** en ambos motores (242 tests, 614 aserciones, Pint limpio). Su aporte no es código nuevo sino **certeza**: la matriz de permisos acota los tres roles por comportamiento, la cobertura de auditoría queda demostrada de punta a punta —incluida la propiedad clave de que una acción fallida no deja rastro (§15)—, el aislamiento se verifica a través del API con 404 cross-tenant, la concurrencia sigue respaldada por índices únicos parciales, y P16/P20 quedan confirmadas.

La única desviación respecto del roadmap —no introducir el Observer— es **deliberada y mejor fundada** que la letra original: la auditoría ya estaba consolidada inline, con garantía transaccional que el Observer no ofrece; añadirlo solo habría duplicado registros. Las observaciones (RLS diferida, float monetario, dependencia de entorno) son deuda **explícita y planificada**, ninguna bloqueante.

Con esto, el **camino completo del MVP V1** —cimientos, identidad, catálogos, núcleo transaccional (caja → órdenes → pagos → inventario), gobernanza (autorizaciones, auditoría), salida (impresión, reportes) y endurecimiento— queda construido, probado en SQLite y PostgreSQL, y verificado transversalmente.

---

# Dictamen

## APROBADO — MVP V1 COMPLETO · CONTRATO DE API LISTO PARA CONGELAR

**Motivo:** los trece sprints (0–12) están entregados conforme al roadmap, la Especificación Funcional y la Arquitectura, verificados en SQLite y PostgreSQL, sin bloqueantes ni P0. La compuerta de cierre del proyecto (§561) se satisface: la reconciliación roles/Spatie (§8) opera, y las decisiones ratificadas —ajustes §2.1 al DER, P16 (contraseñas) y P20 (costo del margen)— están implementadas y verificadas. La única desviación (auditoría inline en vez de Observer) queda documentada y justificada.

**Recomendación post-MVP:** abrir un ciclo de endurecimiento de infraestructura para (1) decidir e implementar RLS como defensa en profundidad, (2) migrar la aritmética monetaria a decimal, (3) documentar las dependencias de entorno de exportación, y (4) blindar la auditoría con un Observer defensivo o pruebas de contrato. Ninguno condiciona el congelamiento del contrato de API V1.
