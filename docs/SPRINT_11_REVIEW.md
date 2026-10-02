# SPRINT_11_REVIEW.md — Revisión Arquitectónica del Sprint 11

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Revisor:** Arquitecto de Software Senior
**Fecha:** 2026-07-01
**Base de evaluación:** `RoadmapImplementacion.md` (Sprint 11, §4 Pruebas §534–537, §5 DoD), `SPRINT_11_ALCANCE.md`, `EspecificacionFuncional.md` (M16, reglas globales, P20/P21), `ArquitecturaBackend.md` (§9–§10, §16–§18), `Convenciones.md`, `DatabaseDictionary.md` (`ordenes`, `detalle_orden`, `pagos`, `sesiones_caja`, `insumos`, `movimientos_inventario`, `auditoria`, `establecimientos.zona_horaria`, `productos.costo_referencia`/`controla_inventario`).
**Objeto:** validar el **Sprint 11 — Reportes y Dashboard (M16)**: Query Services de solo lectura (dashboard, ventas, inventario, caja, medios de pago, cancelaciones, margen), consulta de auditoría (tenant y global), alcance por rol (P21), zona horaria del establecimiento, fuente de costo del margen (P20) y exportación PDF/Excel encolada.

---

## 0. Encuadre de alcance (qué exigía el Sprint 11 y qué se entregó)

El Roadmap define el Sprint 11 como la **capa de lectura** del sistema: entregar la información operativa y de gestión con alcance por rol, en la zona horaria del establecimiento, y exportable a PDF/Excel (paso 10 de §23). El alcance entregado coincide y ratifica las cinco decisiones que la documentación dejaba abiertas (`SPRINT_11_ALCANCE.md` §1):

- **D1** — se instalan las **primeras dependencias de terceros desde el S0**: `barryvdh/laravel-dompdf` (PDF) y `maatwebsite/excel` (Excel), aisladas tras la interfaz `ReporteExporter`.
- **D2** — **exportación encolada sin tabla nueva**: `GenerarReporteExportJob` escribe el archivo en `storage` particionado por tenant; el DER se mantiene congelado en 22 tablas.
- **D3** — fuente del costo del margen (P20) **resuelta por producto** (`controla_inventario`), sin columna ni migración.
- **D4** — alcance por rol (P21): el OPERADOR ve solo su turno (sus sesiones de caja); el ADMIN, todo el establecimiento.
- **D5** — `RangoFechas` (VO) traduce el rango a la zona del establecimiento y lo convierte a UTC `[inicio, fin)`.

**Hallazgo de encuadre.** El sprint es **capa de aplicación pura de solo lectura**: **sin migraciones nuevas** (paridad de esquema por construcción) y **sin cambio de seeder** (los permisos `reportes.ver`/`reportes.ver_limitado`/`auditoria.ver`/`auditoria.global` ya estaban sembrados desde el S1). No se detecta *scope creep*: no se adelantó la consolidación de auditoría por Observer (S12) ni se tocó el núcleo transaccional. La única deuda de infraestructura es de **entorno**, no de código (ver R6).

---

## 1. Verificación punto por punto

Leyenda: ✅ Correcto · ⚠️ Correcto con observación/riesgo · ⛔ Defecto/Pendiente · ⏭️ Diferido por diseño

| # | Área | Estado | Evidencia |
|---|------|--------|-----------|
| 1 | **Zona horaria (M16, D5)** | ✅ | `RangoFechas` resuelve preset/desde-hasta en `establecimientos.zona_horaria` y convierte a UTC `[inicio, fin)`. Unit `RangoFechasTest`: "hoy" en `America/Mexico_City` → `06:00Z`; sin zona → UTC; rango explícito fin exclusivo; `fechaLocal` corrige el día. |
| 2 | **Ventas por periodo** | ✅ | `VentasQuery`: órdenes `pagada` por `cerrada_at` en rango; impuesto reportado aparte (P11); agrupación por día en PHP (paridad). Feature: 2 órdenes → `resumen.total=150`. |
| 3 | **Dashboard del día** | ✅ | `DashboardQuery`: ventas, órdenes pagadas/abiertas y top de productos. Feature: `ventas_total=100`, `ordenes_pagadas=1`, `ordenes_abiertas=1`. |
| 4 | **Medios de pago** | ✅ | `MediosPagoQuery` agrupa `pagos` por tipo en rango. Feature: `filas.0.medio='efectivo'`, `monto=100`. |
| 5 | **Caja (aperturas/cierres/diferencias)** | ✅ | `CajaQuery`: sesiones por `abierta_at` en rango con diferencia + motivo (P5); `monto_sistema` ya solo efectivo (P6/P7). Feature: `resumen.sesiones=1`. |
| 6 | **Inventario (solo ADMIN)** | ✅ | `InventarioQuery`: stock, stock bajo (índice parcial S5), movimientos y mermas. Feature: `stock_bajo=1`, `mermas=1`, `cantidad_mermada=2`. |
| 7 | **Cancelaciones (solo ADMIN)** | ✅ | `CancelacionesQuery` lee de `auditoria` (`orden.item_cancelado`/`orden.anulada`) con usuario, motivo, fecha, entidad. Feature: `total=1`, `filas.0.motivo='Cliente se retiró'`. |
| 8 | **Margen — fuente de costo (P20)** | ✅ | `MargenQuery`: `controla_inventario=false`→`costo_referencia`; `true`→Σ(receta.cantidad×insumo.costo_unitario). Feature: ingreso 200, costo 40+30=70, margen 130. |
| 9 | **Alcance por rol (P21)** | ✅ | `AlcanceReporte`: admin=todo; operador=sus `sesiones_caja.id_usuario_apertura`. Feature `test_operador_solo_ve_su_turno`: admin ve 2 órdenes, operador 1. |
| 10 | **Reportes de gestión solo ADMIN** | ✅ | `ReportePolicy::verGestion` exige `reportes.ver`. Feature: operador → 403 en inventario/cancelaciones/margen. |
| 11 | **Auditoría del tenant (ADMIN)** | ✅ | `AuditoriaController::index` filtra por `id_establecimiento` (Auditoria no usa TenantScope). Feature: admin ve `orden.pagada` (paginada); operador → 403. |
| 12 | **Auditoría global (SUPER_ADMIN)** | ✅ | `AuditoriaController::global` sin filtro de tenant; `AuditoriaPolicy::global`. Feature: super_admin 200; admin → 403. |
| 13 | **Exportación encolada (D2)** | ✅ | `POST /reportes/exportar` → 202 + `descarga_url`; `GenerarReporteExportJob` (cola `reportes`, `tries=3`) re-ejecuta el query y escribe a `storage/exportaciones/{tenant}/{uuid}.{ext}`. Integración: PDF y Excel generan archivo (`Storage::fake`). |
| 14 | **Exportación respeta el alcance del rol** | ✅ | El job viaja con el snapshot del alcance; el operador no exporta gestión. Integración: operador exporta `margen` → 403. |
| 15 | **Solo lectura (D6)** | ✅ | Los Query Services no abren transacción de escritura ni mutan estado; el único efecto (archivo en `storage`) ocurre en el job, fuera de la petición. |
| 16 | **Aislamiento multi-tenant** | ✅ | `TenantScope` en las fuentes; `auditoria` acotada por `id_establecimiento` explícito. Integración `AislamientoReportesTest`: reporte de B no ve datos de A; auditoría del admin solo de su tenant. |
| 17 | **Validación en dos capas (§17)** | ✅ | `RangoReporteRequest`/`ExportarReporteRequest` validan forma (rango, formato, reporte); la interpretación temporal y el alcance viven en el dominio. Feature: `desde>hasta` → 422; reporte inexistente → 422. |
| 18 | **Sin migraciones / paridad** | ✅ | Capa de lectura; suite verde en SQLite y PostgreSQL 17 sin tocar el esquema. |
| 19 | **Serialización por contrato (§18)** | ⚠️ | La auditoría usa `AuditoriaResource` **paginada**; los reportes devuelven el payload agregado `{columnas,filas,resumen}` **sin paginar** (no son entidades sino agregados). Correcto para el volumen de un bar; ver R1. |

**Pruebas ejecutadas en esta revisión:** `php artisan test` → **229 passed (574 assertions)** en SQLite; `php artisan test -c phpunit.pgsql.xml` → **229 passed** en **PostgreSQL 17**; `pint --test` → `passed`. El Sprint 11 añade **24 pruebas** (4 Unit, 10 Feature, 6 Integration; 4 de zona horaria/margen/agregación) sobre las 205 del cierre del S10.

---

## 2. Qué quedó correcto

1. **La zona horaria es de primera clase, no una ocurrencia tardía.** `RangoFechas` centraliza la traducción rango→zona→UTC y la agrupación por día se hace en PHP, de modo que dos establecimientos en husos distintos obtienen cortes de día correctos y la lógica es idéntica en SQLite y PostgreSQL (paridad por construcción, sin funciones de fecha del motor).
2. **El alcance por rol vive en el dominio, no en la policy.** `AlcanceReporte` resuelve el acotamiento del operador a su turno (sus sesiones de caja) y es **serializable**: el mismo alcance viaja al job de exportación como snapshot, de modo que un operador no puede exportar datos fuera de su turno. La policy solo decide *si* ve; el alcance decide *qué* ve.
3. **La fuente de costo del margen (P20) se resolvió sin inventar esquema.** La decisión "por producto" se implementa leyendo `controla_inventario` y la receta ya existentes; el reporte no añadió columnas ni banderas de configuración, y el caso mixto (con y sin control) quedó probado.
4. **La exportación no bloquea y no ensució el DER.** El job encolado re-ejecuta el Query Service (una sola fuente de verdad para el dato interactivo y el exportado) y escribe a `storage` particionado por tenant; no se añadió una tabla de seguimiento, respetando el congelamiento de 22 tablas.
5. **El exportador es genérico y aislado.** El payload homogéneo `{columnas,filas,resumen}` permite que `PdfExporter`/`ExcelExporter` rendericen cualquier reporte sin conocerlo; las librerías de terceros (dompdf, Laravel Excel) quedan detrás de `ReporteExporter`, sin filtrarse al dominio.
6. **La auditoría respeta la frontera de tenant pese a no tener TenantScope.** Como `auditoria` admite `id_establecimiento` nulo (acciones del super_admin), el acotamiento por tenant se hizo explícito en el controlador y en `CancelacionesQuery`; el super_admin es el único que cruza tenants, y solo en `/auditoria/global`.

---

## 3. Qué quedó incompleto

### Diferido por diseño (no bloquea el cierre del Sprint 11)
- **Consolidación de auditoría por Observer** (diff CRUD en maestros) y **matriz de permisos completa** — Sprint 12.
- **Costeo histórico por lote** para el margen — el reporte usa el costo vigente del insumo (R2); el costeo por movimiento queda fuera del MVP.
- **Programación/entrega de reportes** (correo, agendado) y **gráficas del lado servidor** — el backend entrega datos agregados; la visualización es del front.

### Observaciones a gestionar (no bloqueantes)
- **Los reportes agregados no paginan** (R1); la auditoría sí.
- **La exportación se prueba con cola `sync`**; un worker asíncrono real depende del snapshot de tenant/alcance (R3, ya mitigado).
- **Los archivos exportados no tienen política de retención/limpieza** (R4).
- **La descarga es autenticada y acotada al tenant, pero no una URL firmada** (R5), como sugería el ALCANCE.
- **Dependencia de entorno**: `ext-gd` y `ext-zip` (R6).

---

## 4. Riesgos

| ID | Riesgo | Severidad | Impacto |
|----|--------|-----------|---------|
| R1 | **Los reportes agregados devuelven el conjunto completo sin paginar.** Dashboard/ventas/medios-pago/margen resumen; caja/inventario/cancelaciones listan todo el rango. | Baja | Para el volumen de un bar es irrelevante; un rango "año" con miles de movimientos podría crecer. La auditoría (la fuente que más crece) sí pagina. Mitigación futura: paginar o limitar las listas largas. |
| R2 | **El margen usa el costo VIGENTE del insumo, no el histórico del movimiento.** Un cambio de `costo_unitario` posterior a la venta altera el margen retroactivo. | Baja | Aceptado en el ALCANCE (D3) como fuera del MVP; el costeo por lote exigiría leer el costo asentado en `movimientos_inventario.venta`. Anotado como deuda planificada. |
| R3 | **Contexto de tenant en el worker de exportación.** El job re-fija `TenantContext` desde `idEstablecimiento` y reconstruye el alcance desde snapshot, así que funciona en `sync` y en un driver real. | Baja | Resuelto por diseño (el job no depende de `Auth` ni del contexto de la request). Se prueba con `sync`/`Storage::fake`; con Redis/SQS el patrón se mantiene. |
| R4 | **Los archivos exportados se acumulan en `storage/exportaciones/{tenant}`** sin TTL ni limpieza. | Baja | Crecimiento de disco a largo plazo. Mitigación futura: comando de purga o disco temporal con expiración. No afecta la corrección. |
| R5 | **La descarga es una ruta autenticada + acotada al tenant, no una URL firmada.** El ALCANCE (D2) mencionaba URL firmada. | Baja | El aislamiento está garantizado (auth + policy `exportar` + ruta `exportaciones/{tenant}/basename`, sin path traversal); una firma temporal sería un endurecimiento adicional, no un requisito de correctitud. |
| R6 | **Dependencia de entorno: `ext-gd` y `ext-zip`.** Laravel Excel (phpspreadsheet) las exige; estaban deshabilitadas en el `php.ini` local y se habilitaron. | Media | Un entorno nuevo (CI, otro dev, producción) **debe** habilitarlas o `composer install`/la exportación Excel fallará. Debe documentarse en el README/setup. No afecta a los reportes interactivos ni al PDF. |
| R7 | **Aritmética monetaria en float** en los agregados (sumas de importes ya congelados). | Baja | Solo presentación/lectura; mismo criterio común S5–S10. |

---

## 5. Qué debe corregirse antes del Sprint 12

**Bloqueantes:** ninguno. El Sprint 11 cumple su DoD —dashboard y reportes con filtros por tenant, periodo y rol; alcance del operador a su turno (P21); zona horaria del establecimiento; margen con fuente de costo por producto (P20); exportación PDF/Excel encolada; auditoría consultable por admin (tenant) y super_admin (global); suite verde en SQLite y PostgreSQL; sin migraciones; aislamiento probado; policies y validación en dos capas— sin defectos abiertos ni P0.

**Recomendados (cerrar para no arrastrar deuda):**
1. **Documentar en el setup la habilitación de `ext-gd`/`ext-zip`** (R6) para que un entorno limpio compile y exporte Excel.
2. **Definir retención/limpieza de los archivos exportados** (R4) si el volumen lo justifica.

**Heredados (deuda planificada):** decisión formal de RLS, aritmética monetaria en float (R7), consolidación del patrón de auditoría (Observer en S12), costeo histórico por lote del margen (R2), paginación de listas largas de reportes (R1).

---

## 6. Conclusión

El **Sprint 11 está bien implementado, es idiomático y está en verde** en ambos motores (229 tests, 574 aserciones, Pint limpio). Lo esencial —la capa de lectura completa (dashboard, ventas, inventario, caja, medios de pago, cancelaciones, margen) construida con Query Services de solo lectura sobre un `RangoFechas` consciente de la zona horaria, el alcance por rol (P21) que acota al operador a su turno y viaja serializado al job, la fuente de costo del margen resuelta por producto (P20), la auditoría por tenant y global, y la exportación PDF/Excel encolada que no bloquea ni ensucia el DER— está construido y probado. No hay migraciones, de modo que la paridad de esquema se conserva por construcción, y las nuevas dependencias de terceros quedan aisladas tras `ReporteExporter`.

Las observaciones son honestas y **no bloqueantes**: la ausencia de paginación en los agregados, el costeo vigente (no histórico) del margen, la falta de retención de archivos exportados y —la única con severidad media— la **dependencia de entorno de `ext-gd`/`ext-zip`**, que debe documentarse pero no compromete la correctitud. Ninguna impide el inicio del Sprint 12.

---

# Dictamen

## APROBADO PARA SPRINT 12

**Motivo:** la capa de Reportes y Dashboard está entregada conforme al roadmap y al DoD, verificada en SQLite y PostgreSQL, sin bloqueantes ni P0. El Sprint 12 (Consolidación de auditoría y endurecimiento, M15) tiene sus dependencias satisfechas: todo el modelo operativo está construido y ahora también su capa de lectura, de modo que la cobertura de auditoría (Observer en maestros, impersonación P17, reimpresión P14) y la batería transversal (aislamiento, concurrencia, matriz de permisos) pueden verificarse contra un sistema completo.

**Recomendación de arranque:** cablear el Observer + trait `Auditable` en los modelos maestros para el diff CRUD antes/después en JSONB; completar la auditoría de impersonación (P17) y reimpresión (P14, ya emitida en S10); ejecutar la matriz de permisos completa (cada rol × cada acción, incluidos los 🔐) y las pruebas de aislamiento y concurrencia (dobles aperturas de caja, dobles órdenes por mesa); y verificar que la implementación respete las decisiones ya ratificadas P16 (contraseñas) y P20 (costo del margen) antes de congelar el contrato de API del MVP V1.
