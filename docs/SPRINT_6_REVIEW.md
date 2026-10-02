# SPRINT_6_REVIEW.md — Revisión Arquitectónica del Sprint 6

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Revisor:** Arquitecto de Software Senior
**Fecha:** 2026-06-18
**Base de evaluación:** `RoadmapImplementacion.md` (Sprint 6, §4 Pruebas §509–512, §5 DoD), `SPRINT_6_ALCANCE.md`, `EspecificacionFuncional.md` (M10, CU-05/CU-06, F8/F9, matriz Fase 7, reglas globales 5/6/7), `ArquitecturaBackend.md` (§7–§10, §15), `Convenciones.md`, `DatabaseDictionary.md` (tabla `sesiones_caja`).
**Objeto:** validar el **Sprint 6 — Caja (M10)**: ciclo de apertura/cierre con arqueo de efectivo, una sola sesión abierta por establecimiento, diferencia con motivo, y la compuerta de venta (`EnsureCajaAbierta`).

---

## 0. Encuadre de alcance (qué exigía el Sprint 6 y qué se entregó)

El Roadmap define el Sprint 6 como el **inicio del núcleo transaccional**: habilitar la venta mediante el ciclo de caja (paso 5 de §23). El alcance entregado coincide con el roadmap y ratifica con el owner las dos decisiones que la documentación dejaba abiertas (`SPRINT_6_ALCANCE.md` §1):

- **D1** — el `motivo` de diferencia se persiste en una **columna nueva** de `sesiones_caja` (la tabla no la tenía), vía migración.
- **D2** — el histórico es **del establecimiento** para ambos roles; el acotamiento por turno del operador (P21) se difiere a Reportes (S11).

**Hallazgo de encuadre.** A diferencia de los Sprints 4–5 (capa de aplicación pura), este sprint **sí añade una migración** —la primera desde el Sprint 0—, justificada por una carencia real del esquema. No se detecta *scope creep*: no se adelantó Órdenes (S7), Pagos/descuento (S8), la autorización de diferencias ni el reporte de caja (S11).

---

## 1. Verificación punto por punto

Leyenda: ✅ Correcto · ⚠️ Correcto con observación/riesgo · ⛔ Defecto/Pendiente · ⏭️ Diferido por diseño

| # | Área | Estado | Evidencia |
|---|------|--------|-----------|
| 1 | **Apertura de caja** | ✅ | `AbrirCajaService` con `lockForUpdate` sobre el establecimiento + verificación "una sola abierta"; emite `CajaAbierta`; audita `caja.abierta` en la transacción. |
| 2 | **Una sola caja abierta** | ✅ | Verificación de negocio + bloqueo pesimista; respaldo en BD por índice único parcial (pgsql). Test de doble apertura → 409 en ambos motores. |
| 3 | **Cierre con arqueo** | ✅ | `CerrarCajaService`: `monto_sistema = monto_inicial + Σ efectivo`; `diferencia = contado − sistema`; emite `CajaCerrada`; audita `caja.cerrada`. |
| 4 | **Arqueo solo efectivo (P6/P7)** | ✅ | La suma filtra `tipos_pago.nombre = 'efectivo'` vía `pagos ⋈ ordenes`; el test siembra efectivo + tarjeta y verifica que la tarjeta **no** entra. Sin términos de salida (P7). |
| 5 | **Motivo si diferencia ≠ 0 (P5)** | ✅ | Validado en el servicio (la diferencia se calcula en servidor); `MotivoDiferenciaRequeridoException` (422); el cierre se revierte y la caja sigue abierta. |
| 6 | **No cerrar con órdenes abiertas (F9)** | ✅ | `CerrarCajaService` verifica `ordenes` con `estado=abierta`; la tabla existe (vacía hoy), regla activa y válida sin cambios cuando llegue S7. |
| 7 | **Caja cerrada no se reabre** | ✅ | `estado=cerrada` terminal; tras cerrar, `GET actual` → null, segundo cierre → 404, y se puede abrir una caja nueva. |
| 8 | **Matriz de permisos (Fase 7)** | ✅ | `SesionCajaPolicy`: ADMIN y OPERADOR abren/cierran/consultan; SUPER_ADMIN no opera la caja del tenant. Permisos `caja.abrir`/`caja.cerrar` ya sembrados. |
| 9 | **Compuerta de venta** | ✅ | `EnsureCajaAbierta` (alias `caja.abierta`) lanza `SinCajaAbiertaException` (409) sin caja; probado sobre una ruta de prueba con la cadena real. |
| 10 | **Aislamiento multi-tenant** | ✅ | `TenantScope`/`BelongsToTenant`; test de integración (B no ve la caja abierta de A; B abre la suya). |
| 11 | **Atomicidad y auditoría §15** | ✅ | Apertura y cierre en `DB::transaction`; auditoría dentro de la transacción (patrón S4/S5). |
| 12 | **`GET /caja/actual` sin caja** | ✅ | Responde 200 con `data: null` (el front consulta el estado sin tratar la ausencia como error). |
| 13 | **Migración nueva (D1) + paridad** | ⚠️ | Columna `motivo` portable; suite verde en ambos motores y **`up()`/`down()`/`migrate:fresh` verificados en pgsql**. Primera DDL desde S0 — ver R5. |
| 14 | **`EnsureCajaAbierta` sin aplicar a rutas** | ⏭️ | Construido, registrado y probado, pero aún no protege rutas de venta (no existen) — se cablea en S7. Por diseño. |
| 15 | **Σ efectivo = 0 en este sprint** | ⏭️ | `pagos` está vacío hasta S8; la consulta del arqueo queda cableada y correcta, devolviendo 0 hoy. Por diseño. |
| 16 | **Literal de estado `'abierta'`/`'cerrada'`** | ⚠️ | Repetido como string crudo en servicios, middleware, controller y factory; sin enum. Riesgo menor — ver R3. |
| 17 | **Eventos `CajaAbierta`/`CajaCerrada`** | ⚠️ | Se emiten dentro de la transacción, pero **sin listener** (consumo en S11) y **sin assert** en tests. Cobertura pendiente — ver R4. |

**Pruebas ejecutadas en esta revisión:** `php artisan test` → **126 passed (296 assertions)** en SQLite; `php artisan test -c phpunit.pgsql.xml` → **126 passed** en **PostgreSQL 17**; `pint --test` → `passed`; `migrate:fresh` + `migrate:rollback` + `migrate` → limpios en pgsql. El Sprint 6 añade **21 pruebas** (8 Unit, 11 Feature, 2 Integration) sobre las 105 del cierre del S5.

---

## 2. Qué quedó correcto

1. **El ciclo de caja es la compuerta real de la venta.** Apertura y cierre son atómicos y auditados; la invariante "una sola caja abierta" se sostiene con verificación + bloqueo pesimista y, en producción pgsql, con el índice único parcial. `EnsureCajaAbierta` materializa la regla global 5 y queda lista para colgarse de las rutas de órdenes en S7.
2. **Arqueo fiel a P6/P7.** El `monto_sistema` suma **solo** efectivo; el test no se conforma con el cálculo: siembra un pago en tarjeta y comprueba que **no** contamina el arqueo. Sin retiros/fondos. La consulta ya apunta a `pagos`, de modo que cuando S8 escriba pagos reales el arqueo cobra valor sin reescritura.
3. **Motivo de diferencia bien ubicado.** Como la diferencia se calcula en servidor, la obligatoriedad del motivo vive en el servicio (no en el Form Request); el test confirma el 422 **y** que la sesión sigue abierta tras la excepción (atomicidad, no solo el código de estado).
4. **Decisión D1 resuelta con disciplina.** La carencia del esquema se cubrió con una migración mínima y portable, y se verificó el ciclo `up/down/fresh` en el motor objetivo — la paridad se preserva pese a ser la primera DDL desde S0.
5. **CRUD/servicios homogéneos con el proyecto.** Mismo patrón controlador → Form Request → `*Service` transaccional → Resource → Policy que S4/S5, con `authorize()` explícito por acción y excepciones de dominio traducidas por el handler.

---

## 3. Qué quedó incompleto

### Diferido por diseño (no bloquea el cierre del Sprint 6)
- **Aplicar `EnsureCajaAbierta` a las rutas de venta** — Sprint 7 (Órdenes).
- **Σ efectivo con datos reales** — Sprint 8 (Pagos los genera; hoy 0).
- **Autorización de diferencias de caja** — diferida; el roadmap fija "sin autorización" en S6.
- **Acotamiento del histórico por turno del operador (P21)** y **reporte de caja** — Sprint 11.

### Observaciones a gestionar (no bloqueantes)
- **Estados de caja como string crudo.** Conviene un enum `EstadoCaja` (como `TipoMovimiento` en S5) consumido por servicios, middleware y controller (R3).
- **Eventos sin assert.** `CajaAbierta`/`CajaCerrada` se emiten pero ningún test usa `Event::fake` para verificar su despacho (R4).

---

## 4. Riesgos

| ID | Riesgo | Severidad | Impacto |
|----|--------|-----------|---------|
| R1 | **Garantía "una caja abierta" sin índice en SQLite.** El índice único parcial solo existe en PostgreSQL; en SQLite la invariante recae en el bloqueo + verificación, y no hay prueba de concurrencia real (paralela). | Baja | En producción (pgsql) la última línea es el índice. El test cubre el camino secuencial; una carrera verdadera no se ejercita (igual que S5 R7). Aceptable, anotado. |
| R2 | **Aritmética del arqueo en `float`.** `monto_sistema`/`diferencia` se calculan con `(float)` y `round(...,2)` sobre `DECIMAL(12,2)`. | Baja | Para importes normales es seguro; en acumulados grandes podría haber redondeo en el último centavo. Si se exige exactitud estricta, usar BCMath (mismo criterio que S5 R6). |
| R3 | **Literales de estado duplicados.** `'abierta'`/`'cerrada'` aparecen como string en varios puntos; un cambio de catálogo obliga a tocar todos. | Baja | Centralizar en un enum elimina el riesgo y alinea con el patrón ya adoptado en inventario. |
| R4 | **Eventos de caja sin cobertura.** Se despachan pero ningún test lo verifica. | Baja | Un futuro listener (S11) podría romperse sin que la suite avise. Añadir `Event::fake` al cablear el consumo. |
| R5 | **Primera migración nueva desde S0 + factories de `Orden`/`Pago`.** Huella transversal (columna + `HasFactory` en 3 modelos) motivada por D1 y por el test de arqueo. | Baja | Sin efectos secundarios (suite verde, ciclo up/down probado); queda registrado para trazabilidad (análogo a S5 R5). |
| R6 | **`cerrar` localiza la sesión abierta en el controller y el servicio la re-bloquea por id.** Hay una ventana entre la lectura y el lock. | Baja | El servicio re-lee con `lockForUpdate` y re-valida; la invariante "una abierta" no peligra al cerrar. Aceptable; anotado por completitud. |

---

## 5. Qué debe corregirse antes del Sprint 7

**Bloqueantes:** ninguno. El Sprint 6 cumple su DoD —ciclo de caja funcional de extremo a extremo, suite verde en SQLite y PostgreSQL, migración con paridad y ciclo up/down verificado, aislamiento probado, policy por entidad, auditoría transaccional, validación en dos capas, compuerta de venta lista— sin defectos ni P0.

**Recomendados (cerrar para no arrastrar deuda, idealmente antes del Sprint 8, donde Pagos alimenta el arqueo):**
1. **Centralizar el estado de caja** en un enum `EstadoCaja` (R3).
2. **Añadir asserts de eventos** (`Event::fake` para `CajaAbierta`/`CajaCerrada`) al cablear su consumo (R4).
3. **Registrar la decisión** de "sin autorización de diferencias" como explícita hasta que Fase 9 la reabra.

**Heredados (deuda planificada):** decisión formal de RLS, divergencia de timestamps (R4 histórico), unificación del patrón de auditoría (Observer en S12).

---

## 6. Conclusión

El **Sprint 6 está bien implementado, es idiomático y está en verde** en ambos motores (126 tests, 296 aserciones, Pint limpio). Lo esencial —el ciclo de caja atómico y auditado, la invariante "una sola abierta" con doble defensa, el arqueo solo-efectivo verificado contra contaminación de tarjeta, el motivo de diferencia con rollback probado, y la compuerta de venta lista para S7— está construido y probado. La única DDL del sprint se justifica por una carencia del esquema y se verificó su ciclo completo en PostgreSQL, preservando la paridad.

Las observaciones son honestas y **no bloqueantes**: literales de estado por centralizar, eventos sin assert, y salvaguardas (índice parcial, arqueo real) que dependen de motores/sprints posteriores por diseño. Ninguna compromete el inicio del Sprint 7.

---

# Dictamen

## APROBADO PARA SPRINT 7

**Motivo:** la capa de caja está entregada conforme al roadmap y al DoD, verificada en SQLite y PostgreSQL, sin bloqueantes ni P0. El Sprint 7 (Órdenes, M11) tiene sus dependencias satisfechas: **Caja (S6)**, Mesas/Productos (S4) e Inventario (S5); `EnsureCajaAbierta` queda listo para protegerlo.

**Recomendación de arranque:** aplicar `EnsureCajaAbierta` a las rutas de órdenes desde el primer endpoint de S7, y atender los puntos 1–2 de la Sección 5 como deuda menor antes del Sprint 8.
