# SPRINT_5_REVIEW.md — Revisión Arquitectónica del Sprint 5

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Revisor:** Arquitecto de Software Senior
**Fecha:** 2026-06-17
**Base de evaluación:** `RoadmapImplementacion.md` (Sprint 5, §4 Pruebas, §5 DoD), `SPRINT_5_ALCANCE.md`, `EspecificacionFuncional.md` (M07/M08, CU-15/CU-16, validaciones, matriz Fase 7), `ArquitecturaBackend.md` (§7–§10, §15, §17, §18, §20), `Convenciones.md`, `DatabaseDictionary.md` (DER V1.2).
**Objeto:** validar el **Sprint 5 — Inventario base y recetas** (M08 insumos/proveedores/unidades/movimientos, M07 recetas BOM) construido sobre la **capa 2 ya completa** (catálogo, Sprint 4).

---

## 0. Encuadre de alcance (qué exigía el Sprint 5 y qué se entregó)

El Roadmap define el Sprint 5 como el inventario *ledger* (fuente de verdad), su CRUD de insumos/proveedores/unidades propias, los movimientos manuales y las recetas (BOM). El esquema de las 5 tablas ya existía y estaba verificado en PostgreSQL desde el Sprint 0; **este sprint es capa de aplicación pura y no añade migraciones**, por lo que la paridad SQLite↔PostgreSQL se conserva por construcción.

**Hallazgo de encuadre.** El alcance entregado coincide con el roadmap y resuelve formalmente las dos decisiones que el propio roadmap dejaba abiertas ("aclarar sin inventar reglas"), ratificadas con el owner antes de implementar (`SPRINT_5_ALCANCE.md` §1):
- **D1** — salida manual (merma/rotura/consumo) > stock **se bloquea** (el negativo se reserva a la venta, P2).
- **D2** — stock inicial vía movimiento `entrada` (el CRUD de insumo no edita `stock_actual`).

No se detecta *scope creep*: no se adelantó el descuento por venta (S8), la autorización del operador (S9) ni los reportes (S11).

---

## 1. Verificación punto por punto

Leyenda: ✅ Correcto · ⚠️ Correcto con observación/riesgo · ⛔ Defecto/Pendiente · ⏭️ Diferido por diseño

| # | Área | Estado | Evidencia |
|---|------|--------|-----------|
| 1 | **CRUD Proveedores** | ✅ | `ProveedorController` + `GuardarProveedorService`; rutas `GET/POST/PUT/PATCH /proveedores`. |
| 2 | **CRUD Unidades (híbrido P4)** | ✅ | `index` devuelve globales + propias (`IncluyeGlobales`); escritura solo sobre propias (policy + servicio); `DELETE` con bloqueo si está en uso. |
| 3 | **CRUD Insumos + kardex** | ✅ | `InsumoController` con `kardex`; `stock_actual` de solo lectura (D2). |
| 4 | **Movimientos (ledger)** | ✅ | `RegistrarMovimientoService` append-only; actualiza cache + `stock_resultante` en la misma transacción; `lockForUpdate` sobre el insumo. |
| 5 | **Recetas (BOM)** | ✅ | `GestionarRecetaService`; par `producto+insumo` único (servicio + índice). |
| 6 | **Bloqueo D1 (salida > stock)** | ✅ | `StockInsuficienteException` (422); el test de Unit verifica además el **rollback** (stock y ledger intactos). |
| 7 | **Stock como ledger (cache == ledger)** | ✅ | `ReconciliarStockService::calcularDesdeLedger`; test de integración asserta `stock_actual == suma firmada`. |
| 8 | **Atomicidad y auditoría §15** | ✅ | Todos los servicios en `DB::transaction`; auditoría dentro de la transacción (vía servicio, como Sprint 4). |
| 9 | **Policies por entidad (matriz Fase 7)** | ✅ | `Proveedor/Insumo/UnidadMedida/Receta/MovimientoInventario` Policy; entrada/ajuste solo ADMIN, merma ADMIN+OPERADOR. |
| 10 | **Aislamiento multi-tenant** | ✅ | `TenantScope`/`IncluyeGlobales`; test de integración (insumo de A → 404 para B; B no ve unidades propias de A). |
| 11 | **Unidades globales solo lectura (P4)** | ✅ | Doble defensa: `UnidadMedidaPolicy::update/delete` rechaza si `esGlobal()` (403) y el servicio lanza `UnidadGlobalNoEditableException`. |
| 12 | **Validación en dos capas** | ✅ | Forma en Form Requests (`gt:0`, `exists` por tenant, `requiredIf` motivo); estado de negocio (stock, par único, global) en servicios. |
| 13 | **Resources sin fuga de columnas** | ✅ | `InsumoResource` añade `stock_bajo` derivado; sin `id_establecimiento`/`deleted_at`. |
| 14 | **`ajuste` con signo siempre positivo** | ⚠️ | Decisión: `ajuste` suma (+), consistente con "entrada/ajuste positivo" de la EspecFuncional; un ajuste a la baja se hace por `merma`. Limitación real — ver R1. |
| 15 | **`motivo` obligatorio solo en merma/ajuste** | ⚠️ | Sigue la validación literal (EspecFuncional, validaciones M08), pero el flujo F7 nombra motivo también para rotura/consumo. Inconsistencia menor — ver R2. |
| 16 | **Eventos `MovimientoRegistrado`/`StockBajoDetectado`** | ⚠️ | Se emiten dentro de la transacción, pero **sin listener** (consumo en S11) y **sin assert en tests**. Cobertura pendiente — ver R3. |
| 17 | **`ReconciliarStockService`** | ⚠️ | Implementado y probado, pero **sin disparador de producción** (no hay endpoint ni comando artisan); hoy solo lo ejercitan los tests. Salvaguarda lista, no cableada — ver R4. |
| 18 | **`HasFactory` añadido a 7 modelos** | ⚠️ | Cambio transversal necesario para las factories (ningún modelo lo tenía). Correcto y cubierto por la suite; nota de trazabilidad — ver R5. |

**Pruebas ejecutadas en esta revisión:** `php artisan test` → **98 passed (226 assertions)** en SQLite; `php artisan test -c phpunit.pgsql.xml` → **98 passed** en **PostgreSQL 17**; `pint --test` → `passed`. El Sprint 5 añade 31 pruebas (9 Unit, 23 Feature, 2 Integration — repartidas en 8 archivos) sobre las 67 del Sprint 4.

---

## 2. Qué quedó correcto

1. **El ledger es realmente la fuente de verdad.** `RegistrarMovimientoService` inserta el movimiento, fija `stock_resultante` y actualiza `insumos.stock_actual` en una sola transacción con `lockForUpdate` sobre el insumo; `ReconciliarStockService` puede recomputar el cache desde la suma firmada del ledger, y un test de integración demuestra que ambos coinciden. La invariante "cache = ledger" está construida y verificada.
2. **D1 implementado con rollback verificado.** La salida manual que dejaría stock negativo se bloquea, y el test no solo comprueba el 422 sino que **el stock y el ledger quedan intactos** tras la excepción — es la prueba correcta de la atomicidad, no solo del código de estado.
3. **Unidades híbridas P4 con doble defensa.** La lectura mezcla globales + propias por scope; la escritura sobre globales se rechaza tanto en la policy (403) como en el servicio (excepción de dominio), de modo que ninguna ruta de entrada puede mutar una unidad predefinida.
4. **Matriz de permisos fiel a Fase 7.** El gating por tipo de movimiento (entrada/ajuste solo ADMIN; merma/rotura/consumo también OPERADOR) se resuelve en `MovimientoController` mapeando `tipo → ability`, con la policy como única autoridad; el test confirma el 403 del operador en entrada/ajuste.
5. **CRUD homogéneo con el resto del proyecto.** Mismo patrón controlador → Form Request → `Guardar*Service` → Resource que el Sprint 4, con `authorize()` explícito por acción y `stock_actual` deliberadamente fuera del alcance del CRUD (D2).
6. **Sin migraciones nuevas ⇒ paridad pg preservada.** Toda la lógica recae sobre tablas ya verificadas en el motor objetivo; la suite verde en PostgreSQL lo confirma sin tocar el esquema.

---

## 3. Qué quedó incompleto

### Diferido por diseño (no bloquea el cierre del Sprint 5)
- **Descuento automático por venta** (M08 al cobrar) — Sprint 8.
- **Flujo de autorización del operador** para entrada/ajuste — Sprint 9 (hoy el operador simplemente carece del permiso directo → 403).
- **Consumo de `StockBajoDetectado`** (alertas/reporte de inventario) — Sprint 11.
- **`RevertirMovimientoService`** (salvaguarda de la venta) — Sprint 8.

### Observaciones a gestionar (no bloqueantes)
- **`ReconciliarStockService` sin disparador de producción.** Existe y se prueba, pero ningún endpoint ni comando lo invoca; conviene exponer un `php artisan inventario:reconciliar` (o tarea programada) para que la salvaguarda sea operativa, no solo testable (R4).
- **Eventos sin assert.** `MovimientoRegistrado`/`StockBajoDetectado` se emiten pero ningún test usa `Event::fake` para verificar su despacho ni la condición de stock bajo (R3).
- **`stock_bajo`/alerta sin prueba directa.** La bandera del Resource y el filtro `?stock_bajo=` no tienen un test propio.

---

## 4. Riesgos

| ID | Riesgo | Severidad | Impacto |
|----|--------|-----------|---------|
| R1 | **`ajuste` modelado como incremento (+) únicamente.** Un ajuste de inventario real puede reconciliar a la baja; aquí una corrección negativa debe registrarse como `merma`. | Media | Si el negocio necesita "ajuste por conteo" a la baja con su propia semántica/auditoría, habrá que extender el servicio (signo explícito o tipo `ajuste_negativo`). Documentado, pero podría sorprender al cablear el inventario físico. Revisar antes del Sprint 11 (reportes de ajustes). |
| R2 | **`motivo` no obligatorio en `rotura`/`consumo_interno`.** Se siguió la validación literal (merma/ajuste), pero el flujo F7 y la regla global 19 ("toda merma, rotura, ajuste y consumo se registra y audita") sugieren exigir motivo también ahí. | Baja | Movimientos de pérdida sin justificación; ruido en auditoría/reportes de mermas. Decidir si se amplía `requiredIf` a los tres tipos de salida. |
| R3 | **Sign-map duplicado en dos servicios.** `RegistrarMovimientoService` (SUMAN/RESTAN) y `ReconciliarStockService` (SUMAN/resto) definen el signo por tipo por separado. | Baja | Si se añade un tipo nuevo, ambos deben actualizarse o el cache divergirá del ledger. Centralizar el mapa de signos (enum/clase) elimina el riesgo. |
| R4 | **`ReconciliarStockService` no cableado a producción.** Salvaguarda de integridad sin disparador. | Baja | Una divergencia cache↔ledger (por un bug futuro) no tendría forma operativa de corregirse sin código nuevo. Exponer comando/tarea. |
| R5 | **`HasFactory` agregado a 7 modelos.** Cambio transversal fuera del dominio estricto del sprint, motivado por las pruebas. | Baja | Sin efectos secundarios (suite completa verde), pero amplía la huella del sprint; queda registrado para trazabilidad. |
| R6 | **Aritmética de stock en `float`.** El servicio calcula `stock_resultante` con `(float)` y lo persiste en `DECIMAL(12,3)`. | Baja | Para cantidades normales es seguro; en valores extremos podría haber redondeo en el último decimal. Si se requiere exactitud estricta, usar BCMath sobre las cantidades decimales. |
| R7 | **Bloqueo de stock sin prueba de concurrencia.** `lockForUpdate` está, pero ningún test ejerce dos movimientos simultáneos. | Baja | El DoD de concurrencia formal es de Caja/Órdenes (S6–S7); aquí el lock queda sin verificación directa. Aceptable, anotado. |

---

## 5. Qué debe corregirse antes del Sprint 6

**Bloqueantes:** ninguno. El Sprint 5 cumple su DoD —código funcional de extremo a extremo, suite verde en SQLite y PostgreSQL, aislamiento probado, policies por entidad, auditoría transaccional, validación en dos capas, resources por contrato— sin defectos ni P0 heredados.

**Recomendados (cerrar para no arrastrar deuda):**
1. **Centralizar el mapa de signos por tipo** de movimiento en un único lugar consumido por `RegistrarMovimientoService` y `ReconciliarStockService` (R3).
2. **Decidir el contrato de `motivo`** para rotura/consumo_interno y, si procede, ampliar `requiredIf` (R2).
3. **Exponer `ReconciliarStockService`** vía comando artisan o tarea programada (R4).
4. **Añadir asserts de eventos** (`Event::fake` para `MovimientoRegistrado`/`StockBajoDetectado`) y una prueba del filtro `stock_bajo` (R3).
5. **Registrar la decisión sobre `ajuste` a la baja** (R1) o dejar explícito que la corrección negativa es `merma`, antes de los reportes de ajustes (S11).

**Heredados (deuda planificada):** decisión formal de RLS, divergencia de timestamps R4, unificación del patrón de auditoría (Observer en S12), factories restantes.

---

## 6. Conclusión

El **Sprint 5 está bien implementado, es idiomático y está en verde** en ambos motores (98 tests, 226 aserciones, Pint limpio). Lo esencial del sprint —el inventario como *ledger* con cache reconciliable, el bloqueo D1 con rollback verificado, las unidades híbridas con doble defensa, la matriz de permisos por tipo de movimiento y las recetas con par único— está construido y probado, y el alcance respeta el roadmap sin adelantar el núcleo transaccional. **No hay scope creep ni P0**: el esquema ya corría en PostgreSQL y este sprint no añade DDL.

Las observaciones son honestas y todas **no bloqueantes**: un par de decisiones semánticas a ratificar (`ajuste` solo positivo; `motivo` en rotura/consumo), duplicación menor del mapa de signos, una salvaguarda (`ReconciliarStockService`) lista pero sin disparador de producción, y algunas asserts de eventos pendientes. Ninguna compromete el inicio del Sprint 6 (Caja), que depende de la identidad y la caja, no del inventario.

---

# Dictamen

## APROBADO PARA SPRINT 6

**Motivo:** la capa de inventario y recetas está entregada conforme al roadmap y al DoD, verificada en SQLite y PostgreSQL, sin bloqueantes ni P0. El Sprint 6 (Caja, M10) tiene sus dependencias satisfechas (identidad/usuarios de la capa 1).

**Recomendación de arranque:** atender los puntos 1–5 de la Sección 5 como deuda menor del dominio de inventario antes del Sprint 8 (donde Pagos dispara el descuento por venta y la reconciliación cobra criticidad), no como condición para abrir el Sprint 6.

---

# Adenda — Cierre de deuda menor (post-revisión, 2026-06-18)

Antes de abrir el Sprint 6 se cerraron los cinco recomendados de la Sección 5. Estado tras el cierre: **suite 105/105 verde en SQLite y PostgreSQL 17 (241 aserciones), Pint limpio** (Sprint 5 cerró en 98; el cierre añade 7 pruebas).

| Rec. | Acción tomada | Evidencia |
|------|---------------|-----------|
| **R3 · mapa de signos** | Se centralizó en el enum `App\Domain\Inventario\TipoMovimiento` (fuente única de `signo()`, `esManual()`, `requiereMotivo()`). Lo consumen `RegistrarMovimientoService`, `ReconciliarStockService` y `RegistrarMovimientoRequest`; añadir un tipo se hace en un solo lugar. | Enum + refactor de los 3 consumidores. |
| **R2 · motivo en rotura/consumo** | **Decisión del owner:** se exige `motivo` en `ajuste`, `merma`, `rotura` y `consumo_interno` (regla global 19). `requiredIf` derivado de `TipoMovimiento::conMotivo()`. La `entrada` no exige motivo. | `RegistrarMovimientoRequest`; tests `test_motivo_obligatorio_en_ajuste_merma_rotura_y_consumo`, `test_entrada_no_exige_motivo`. |
| **R4 · reconciliación cableada** | Comando `php artisan inventario:reconciliar` (`--insumo=`, `--dry-run`): recorre los insumos de todos los tenants, compara cache↔ledger y corrige divergencias. | `ReconciliarInventarioCommand`; tests `corrige la divergencia`, `dry-run no persiste`. |
| **R3 · asserts de eventos / filtro** | `Event::fake` verifica `MovimientoRegistrado` (siempre) y `StockBajoDetectado` (solo al caer en/bajo el mínimo, no por encima). Prueba directa del filtro `?stock_bajo=`. | `RegistrarMovimientoServiceTest` (3 nuevas), `InsumoTest::test_filtro_stock_bajo...`. |
| **R1 · ajuste a la baja** | **Decisión formal:** `ajuste` solo corrige al alza (+); una corrección a la baja por conteo se registra como `merma`, que tiene su propia auditoría de pérdida. Documentado en el docblock de `TipoMovimiento`. No se cambia el contrato. | `TipoMovimiento` (docblock); este registro. |

**Heredados (deuda planificada, sin cambios):** decisión formal de RLS, divergencia de timestamps R4, unificación del patrón de auditoría (Observer en S12). R6 (aritmética en `float`) y R7 (concurrencia sin prueba) quedan anotados como aceptables; no se tocaron.
