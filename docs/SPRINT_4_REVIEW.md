# SPRINT_4_REVIEW.md — Revisión Arquitectónica del Sprint 4

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Revisor:** Arquitecto de Software Senior
**Fecha:** 2026-06-17
**Base de evaluación:** `RoadmapImplementacion.md` (Sprint 4, §4 Pruebas, §5 DoD), `SPRINT_4_ALCANCE.md`, `SPRINT_1_REVIEW.md` y `SPRINT_2_ALCANCE.md` (capa 1 heredada), `ArquitecturaBackend.md` (§7–§10, §15, §17, §18, §20), `Convenciones.md` (§1–§7), `DatabaseDictionary.md` (DER V1.2).
**Objeto:** validar el **Sprint 4 — Catálogo de venta y posiciones** (M05 Categorías, M06 Productos, M09 Mesas, M13 Impresoras) construido sobre la **capa 1 ya completa** (M01–M04 + auditoría base + M03 Configuración).

---

## 0. Encuadre de alcance (qué exigía el Sprint 4 y qué se entregó)

El Roadmap define el Sprint 4 como el CRUD de las entidades estáticas que la orden referencia directamente: **M05 Categorías, M06 Productos, M09 Mesas (con estado derivado preparado) y M13 Impresoras**, con sus servicios `Guardar*`, policies, resources, form requests, soft delete, unicidades por tenant y auditoría. Es la **capa 2 (catálogos que consume la venta)**; no toca el núcleo transaccional.

**Hallazgo de encuadre.** La numeración real diverge del roadmap (ver `SPRINT_1_ALCANCE.md` §3): lo entregado como "Sprint 1" fusionó M01+M02+M04, el "Sprint 2" cerró M03, y **no existe un "Sprint 3" separado** (M04 ya estaba). Por tanto este "Sprint 4" entrega la capa 2 sobre una capa 1 efectivamente cerrada. El alcance del sprint se respeta literalmente: las 4 entidades, sin adelantar M07 Recetas ni M08 Inventario (correctamente diferidos al Sprint 5). No se detecta *scope creep*.

---

## 1. Verificación punto por punto

Leyenda: ✅ Correcto · ⚠️ Correcto con observación/riesgo · ⛔ Defecto/Pendiente bloqueante · ⏭️ Diferido por diseño

| # | Área | Estado | Evidencia |
|---|------|--------|-----------|
| 1 | **CRUD M05 Categorías** | ✅ | `CategoriaController` + `GuardarCategoriaService` (crear/actualizar/cambiarEstado). Rutas `GET/POST/PUT/PATCH /categorias`. |
| 2 | **CRUD M06 Productos** | ✅ | `ProductoController` + `GuardarProductoService`. Filtro `?id_categoria=`, carga ansiosa de `categoria`, `activar` alterna `disponible`. |
| 3 | **CRUD M09 Mesas** | ✅ | `MesaController` + `GuardarMesaService`. Número único por tenant; estado `ocupada/libre` **derivado**, no columna. |
| 4 | **CRUD M13 Impresoras** | ✅ | `ImpresoraController` + `GuardarImpresoraService`. `tipo` acotado al ENUM. |
| 5 | **Atomicidad y auditoría §15** | ✅ | Los 4 servicios envuelven en `DB::transaction` y registran en `auditoria` **dentro de la misma transacción** vía `RegistrarAuditoriaService` (acción/entidad/antes/después). |
| 6 | **Policies (solo ADMIN)** | ✅ | `ProductoPolicy`, `CategoriaProductoPolicy`, `MesaPolicy`, `ImpresoraPolicy` mapean a `can('{recurso}.gestionar')`; super_admin vía `Gate::before`. Sin reglas de estado en policy (DoD §5.4). |
| 7 | **Aislamiento multi-tenant** | ✅ | El `TenantScope` filtra por `id_establecimiento`; un id ajeno → 404 (`findOrFail` sobre query ya scopeada). Probado en `CatalogoAislamientoTest`. |
| 8 | **Unicidad de número de mesa por tenant** | ✅ | `GuardarMesaRequest` con `Rule::unique(...)->where(id_establecimiento del TenantContext)->whereNull(deleted_at)->ignore(id)`; respaldada por índice parcial en migración. Tenant tomado del contexto, nunca del cliente. |
| 9 | **Categoría de producto del mismo tenant** | ✅ | `GuardarProductoRequest`: `Rule::exists('categorias_producto')->where(id_establecimiento del TenantContext)`. Impide vincular categoría ajena. |
| 10 | **Contrato `controla_inventario=true` sin receta** | ✅ | El catálogo **permite** crearlo (documentado en `GuardarProductoService:11`); el descuento dependerá de la receta (Sprint 5). No bloquea ni rompe. |
| 11 | **Advertencia al desactivar categoría con productos** | ✅ | `CategoriaController::activar` **advierte, no bloquea** (mensaje en la respuesta cuando `productos()->exists()`). |
| 12 | **Estado derivado de mesa** | ✅ | `MesaResource` calcula `estado` desde `ordenAbierta` con `relationLoaded(...)`; sin orden ⇒ `libre`. Defensivo: la relación M11 aún no existe y no rompe. |
| 13 | **ENUM de impresora fiel al DER V1.2** | ✅ | `['ticket','barra','cocina','admin']` coincide con `DatabaseDictionary.md:262` (migración + Form Request). |
| 14 | **`activar` alterna booleano, no soft delete** | ✅ | `PATCH .../activar` alterna `activo`/`activa`/`disponible`, mismo patrón que Establecimiento/Usuario; `SoftDeletes` queda disponible sin endpoint `DELETE` (el roadmap solo lista GET/POST/PUT/PATCH). |
| 15 | **API Resources sin fuga de columnas** | ✅ | `ProductoResource`/`MesaResource`/etc. exponen solo el contrato; sin `deleted_at`/`id_establecimiento` internos. |
| 16 | **Paginación y validación en dos capas** | ✅ | `ApiResponse::coleccion` con `perPage` acotado; validación de forma en Form Requests, regla de estado (advertencia categoría) en controlador/servicio. |
| 17 | **Validación contra PostgreSQL** | ✅ | Reproducida la secuencia del CI contra **PostgreSQL 17** real (2026-06-17): `migrate` + `rollback` + `migrate:fresh --seed`, suite `php artisan test -c phpunit.pgsql.xml` → **67/67 verde (148 aserciones)** y `pint --test` → `passed`. Ver R1 (cerrada). |
| 18 | **Auditoría CRUD por Observer** | ⏭️ | La auditoría aquí se hace **vía servicio**, no por Observer de maestros; la consolidación por Observer es del Sprint 12 (roadmap). Correcto para el Sprint 4. |

**Pruebas ejecutadas en esta revisión:** `php artisan test` → **67 passed (148 assertions)** sobre SQLite `:memory:`. Incluye 20 Feature de catálogo + 2 Integration (aislamiento + auditoría de catálogo). Sin regresión respecto a sprints previos.

---

## 2. Qué quedó correcto

1. **CRUD homogéneo y idiomático en las 4 entidades.** Mismo patrón controlador → Form Request → `Guardar*Service` (crear/actualizar/cambiarEstado) → Resource, con `authorize()` explícito en cada acción. Bajo acoplamiento y altísima consistencia interna.
2. **Atomicidad y auditoría fieles a §15.** Cada escritura va en `DB::transaction` y emite su entrada de `auditoria` dentro de la misma transacción, con diff antes/después acotado por listas de campos (`CAMPOS`), evitando volcar columnas sensibles o irrelevantes.
3. **Aislamiento por tenant robusto y barato.** Todo el filtrado descansa en el `TenantScope`; los controladores no reimplementan el `where(id_establecimiento)`, y el `findOrFail` sobre la query scopeada produce el 404 correcto ante ids ajenos. El tenant siempre se toma del `TenantContext`, nunca del payload.
4. **Unicidades por tenant bien planteadas.** Número de mesa único por establecimiento ignorando soft-deletes y la propia fila en edición; categoría de producto validada contra el mismo tenant. Form Request + índice parcial = defensa en dos capas.
5. **Contratos de frontera explícitos y documentados en código.** `controla_inventario` sin receta, estado de mesa derivado y advertencia (no bloqueo) al desactivar categoría están comentados en el propio código y alineados con `SPRINT_4_ALCANCE.md` §2.
6. **Fidelidad al DER V1.2.** El ENUM de impresora coincide con el diccionario; los nombres de bandera (`activo`/`activa`/`disponible`) respetan la divergencia de género ya documentada (DER §4).
7. **Sin scope creep.** No se adelantó M07/M08; las relaciones aún inexistentes (orden de mesa) se consultan de forma defensiva sin acoplar el catálogo al núcleo transaccional.

---

## 3. Qué quedó incompleto

### Diferido por diseño (no bloquea el cierre del Sprint 4)
- **Auditoría CRUD por Observer de maestros** — consolidada en el Sprint 12; hoy se cubre vía servicio (suficiente para el DoD del sprint).
- **Estado real de mesa ocupada** — se conecta con M11 (Sprint 7); hoy `libre` por defecto, con el *hook* preparado.
- **Generación de comanda/ticket de impresoras** — capa 5 (Sprint 10); aquí solo el CRUD.

### Observaciones a gestionar (no bloqueantes)
- **Lectura del catálogo limitada a ADMIN.** `viewAny`/`view` exigen `{recurso}.gestionar` (solo ADMIN). Cuando llegue M11 Órdenes (Sprint 7), el **operador necesitará leer** productos/categorías/mesas para vender. No es un defecto del Sprint 4 (el roadmap lo describe como catálogo gestionado por ADMIN), pero debe planificarse un permiso de lectura para el operador antes o durante el Sprint 7. Ver R3.
- **Review independiente del Sprint 2 ausente.** Existe `SPRINT_2_ALCANCE.md` pero no `SPRINT_2_REVIEW.md`; la cadena de DoD verificable que sí tuvieron Sprint 0 y 1 quedó sin cerrar para la pieza M03. No afecta al Sprint 4, pero es deuda de proceso.

### Deuda heredada aún abierta (sin cambios)
- **Factories 4/22** — `Insumo`, `CategoriaProducto`, `Producto`, `Mesa`, `Impresora` y otras se añaden cuando su módulo entra en pruebas; el Sprint 5 necesitará `Insumo`/`Producto`/`Receta`.
- **Decisión formal de RLS** (Arq §7) — diferida de facto.
- **Divergencia de timestamps R4** — sin ratificar ni revertir en el DER.
- **Patrón de auditoría mixto (R7)** — unificación pendiente para Sprint 12.

---

## 4. Riesgos

| ID | Riesgo | Severidad | Impacto |
|----|--------|-----------|---------|
| R1 | ~~**Verificación pg de este sprint apoyada en CI/alcance, no re-ejecutada en la revisión.**~~ **CERRADA (2026-06-17).** Se reprodujo localmente la secuencia exacta del `ci.yml` contra **PostgreSQL 17** real: `migrate`/`rollback`/`migrate:fresh --seed` en verde, **suite 67/67 (148 aserciones)** con `phpunit.pgsql.xml`, y `pint --test` `passed`. La paridad SQLite↔PostgreSQL queda verificada en el motor objetivo (incluidos índices únicos parciales y tipos JSONB). | ~~Baja~~ Cerrada | Sin impacto residual. |
| R2 | **Permisos del Sprint 5 ya sembrados anticipadamente.** `RolesPermisosSeeder` ya incluye `insumos.gestionar`, `proveedores.gestionar`, `unidades.gestionar`, `recetas.gestionar`. | Baja | Sin impacto funcional (no hay endpoints que los consuman aún); solo nota de trazabilidad: el catálogo de permisos se adelantó al módulo. |
| R3 | **Lectura de catálogo no disponible para el operador.** Las policies son ADMIN-only en lectura. | Media | Si no se añade un permiso de lectura para el operador, el Sprint 7 (Órdenes) se bloqueará al intentar listar productos/mesas desde el rol que realmente vende. Planificar en el diseño del Sprint 7. |
| R4 | **`update` con `sometimes|required` permite PUT parcial.** En edición, un PUT que omita campos no los borra, pero el contrato REST de PUT suele implicar reemplazo total. | Baja | Comportamiento tipo PATCH bajo verbo PUT; consistente con el resto del proyecto y documentado de facto, pero conviene homogeneizar la semántica antes de congelar el contrato de API (Sprint 12). |
| R5 | **Auditoría CRUD vía servicio, no por Observer.** Un alta/edición que no pase por el servicio (p. ej. un seeder o un fix por consola) no quedaría auditada. | Baja | Aceptable mientras toda escritura pase por los servicios; el Observer del Sprint 12 cierra el hueco. |

---

## 5. Qué debe corregirse antes del Sprint 5

**Bloqueantes:** ninguno. A diferencia de los Sprints 0 y 1, el Sprint 4 **no arrastra ningún P0**: el esquema ya se validó contra PostgreSQL en el Sprint 1, este sprint no añade migraciones, la suite está en verde y las policies/auditoría/aislamiento cumplen el DoD.

**Recomendados (cerrar para no arrastrar deuda):**
1. ~~Confirmar el último run de CI contra PostgreSQL en verde.~~ **HECHO (2026-06-17):** secuencia del `ci.yml` reproducida contra PostgreSQL 17 real, suite 67/67 verde y Pint limpio (R1 cerrada).
2. **Planificar el permiso de lectura del catálogo para el operador** como entrada del diseño del Sprint 7 (R3), de modo que el núcleo de venta no se bloquee.
3. **Preparar las factories que el Sprint 5 necesita** (`Insumo`, `Producto`, `Receta`, `MovimientoInventario`) al iniciar sus pruebas (Convenciones §7.4).
4. (Opcional, proceso) **Emitir un `SPRINT_2_REVIEW.md`** breve para cerrar la cadena de DoD de M03 Configuración.

**Heredados (gestionar como deuda planificada):** decisión de RLS, divergencia de timestamps R4, unificación del patrón de auditoría (Observer en Sprint 12), factories restantes.

---

## 6. Conclusión

El **Sprint 4 está bien implementado, idiomático y en verde** (67 tests, 148 aserciones, Pint limpio según alcance). Las cuatro entidades del catálogo (categorías, productos, mesas, impresoras) entregan un CRUD homogéneo, atómico y auditado dentro de transacción, con aislamiento por tenant apoyado en el `TenantScope`, unicidades por establecimiento en dos capas, policies ADMIN mapeadas a permisos y resources sin fuga de columnas internas. Los contratos de frontera del sprint —`controla_inventario` sin receta, estado de mesa derivado, advertencia (no bloqueo) al desactivar categoría con productos— están implementados y documentados en el propio código, y el ENUM de impresora es fiel al DER V1.2. **No hay scope creep** ni defectos bloqueantes.

A diferencia de los Sprints 0 y 1, **no se arrastra ningún P0**: el esquema ya corrió en el motor objetivo, el sprint no añade DDL y la suite está verde. Las observaciones identificadas son **forward-looking** (lectura de catálogo para el operador en el Sprint 7) o de **proceso/deuda planificada** (paridad pg re-confirmable por CI, ausencia de review formal del Sprint 2, factories y Observer pendientes), ninguna de las cuales compromete el inicio del Sprint 5.

---

# Dictamen

## APROBADO PARA SPRINT 5

**Motivo:** la capa 2 (catálogo de venta) está entregada conforme al roadmap y al DoD, sin bloqueantes ni P0 heredados. El Sprint 5 (Inventario base y recetas, M08 + M07) tiene satisfechas todas sus dependencias: capa 1 completa, productos existentes para vincular recetas e incluso los permisos de inventario ya sembrados.

**Recomendación de arranque:** abordar el Sprint 5 atendiendo en paralelo los puntos 1–3 de la Sección 5 (confirmar CI pg en verde, planificar la lectura de catálogo para el operador de cara al Sprint 7, y preparar las factories de inventario/recetas). Ninguno bloquea, pero cerrarlos temprano evita arrastrar deuda al núcleo transaccional.
