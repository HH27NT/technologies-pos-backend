# SPRINT_7_REVIEW.md — Revisión Arquitectónica del Sprint 7

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Revisor:** Arquitecto de Software Senior
**Fecha:** 2026-06-19
**Base de evaluación:** `RoadmapImplementacion.md` (Sprint 7, §4 Pruebas §514–517, §5 DoD), `SPRINT_7_ALCANCE.md`, `EspecificacionFuncional.md` (M11, CU-07/08/09/10/11, matriz Fase 7, reglas globales 9–13), `ArquitecturaBackend.md` (§7–§10, §15), `Convenciones.md`, `DatabaseDictionary.md` (`ordenes`, `detalle_orden`).
**Objeto:** validar el **Sprint 7 — Órdenes y totalizador (M11)**: ciclo de vida de la orden (crear mesa/barra, agregar/modificar ítems, totalizar con impuesto configurable, comanda, descuento del cajero) y cancelación/anulación directa por ADMIN, **antes del cobro**.

---

## 0. Encuadre de alcance (qué exigía el Sprint 7 y qué se entregó)

El Roadmap define el Sprint 7 como la continuación del núcleo transaccional sobre la compuerta de caja del S6 (paso 6 de §23): el **ciclo de vida de la orden hasta antes del cobro**. El alcance entregado coincide con el roadmap y ratifica la única decisión que la documentación dejaba abierta (`SPRINT_7_ALCANCE.md` §1):

- **D1** — base imponible del impuesto cuando hay descuento de orden: **impuesto sobre el neto**, `impuesto = round(tasa/100 × (subtotal − descuento), 2)`; el descuento reduce la base gravable.

**Hallazgo de encuadre.** El sprint vuelve a la dinámica de **capa de aplicación pura**: **sin migraciones nuevas** (el esquema `ordenes`/`detalle_orden` se migró en el S0, incluida la columna `enviado` y el índice único parcial). No se detecta *scope creep*: el inventario **no se toca** (P1), no se adelantó el cobro ni los pagos (S8), ni el flujo de autorización de dos niveles (S9), ni la impresión física (S10). La cancelación/anulación se entrega como **ADMIN directo**, tal como el roadmap la asigna a este sprint.

---

## 1. Verificación punto por punto

Leyenda: ✅ Correcto · ⚠️ Correcto con observación/riesgo · ⛔ Defecto/Pendiente · ⏭️ Diferido por diseño

| # | Área | Estado | Evidencia |
|---|------|--------|-----------|
| 1 | **Crear orden (mesa/barra)** | ✅ | `CrearOrdenService`: exige caja abierta; `lockForUpdate` sobre el establecimiento (folio) y sobre la mesa; emite `OrdenCreada`; audita `orden.creada`. Tests de mesa (201, folio, abierta) y barra (sin mesa). |
| 2 | **Caja abierta exigida** | ✅ | Doble defensa: middleware `caja.abierta` en la ruta **y** verificación en el servicio (`SinCajaAbiertaException`, 409). Test `crear sin caja → 409`. |
| 3 | **Una sola orden abierta por mesa** | ✅ | Verificación de negocio + bloqueo pesimista sobre la mesa; respaldo en BD por índice único parcial (pgsql). Test de doble orden en la misma mesa → 409 (`MesaOcupadaException`). |
| 4 | **Folio continuo por tenant (P12)** | ⚠️ | `GeneraFolio::proximoCorrelativo` dentro de la transacción, serializado con `lockForUpdate` sobre el establecimiento; padding `000001`. Continuidad y aislamiento probados. Ver R1 (serialización) y R2 (umbral de 7 dígitos). |
| 5 | **Agregar ítem + precio congelado** | ✅ | `AgregarItemService`: solo orden `abierta`; producto disponible y del tenant; `precio_unitario = producto.precio_venta` congelado; recalcula. Test verifica que cambiar el catálogo no altera la orden. |
| 6 | **Modificar cantidad (solo no enviado)** | ✅ | `ModificarItemService`: solo `enviado=false` y `activo`; recalcula. Ítem enviado → 422 (`OrdenNoModificableException`). |
| 7 | **Confirmar comanda · NO toca inventario (P1)** | ✅ | `ConfirmarComandaService` marca `enviado=true`, emite `ItemConfirmado`; comanda incremental. Test asserta `movimientos_inventario` = 0 incluso con producto `controla_inventario=true`. |
| 8 | **Totalizador · impuesto sobre el neto (P11/D1)** | ✅ | `TotalizadorOrden` (calculadora pura): `base = subtotal − descuento`, `impuesto = aplica ? round(tasa/100 × base,2) : 0`, `total = base + impuesto`. Unit (neto, sin impuesto, redondeo) + feature end-to-end (subtotal 100, desc 20 → impuesto 12.80, total 92.80). |
| 9 | **Descuento del cajero · sin autorización (P10)** | ✅ | `AplicarDescuentoService`: monto fijo a nivel orden; OPERADOR aplica sin aprobación; tope `≤ subtotal` (→ 422 `DescuentoInvalidoException`). Tests con OPERADOR y descuento > subtotal. |
| 10 | **No modificar orden cerrada** | ✅ | Toda mutación valida `EstadoOrden::esModificable()`; orden `pagada`/`anulada` → 422. Tests sobre orden anulada y pagada. |
| 11 | **Cancelar ítem (ADMIN directo)** | ✅ | `CancelarItemService`: `estado_item=cancelado` + `cancelado_at`; recalcula (el cancelado no cuenta); audita `orden.item_cancelado` con motivo. OPERADOR → 403. |
| 12 | **Anular orden (ADMIN directo)** | ✅ | `AnularOrdenService`: `estado=anulada` + `cerrada_at`; mesa liberada por derivación (regla 11, sin flag persistido); emite `OrdenAnulada`; audita. OPERADOR → 403. |
| 13 | **Policy de orden (matriz Fase 7)** | ✅ | `OrdenPolicy`: `crear`/`agregarItem`/`aplicarDescuento` (ADMIN+OPERADOR), `cancelarItem`/`anular` (solo ADMIN), `viewAny`/`view`. Permisos ya sembrados en S0. |
| 14 | **Aislamiento multi-tenant** | ✅ | `TenantScope`/`BelongsToTenant`; folio y "una abierta por mesa" por establecimiento; orden de otro tenant → 404 (test) e integración B-no-ve-A. |
| 15 | **Atomicidad y auditoría §15** | ✅ | Los 7 servicios envuelven en `DB::transaction`; auditoría dentro de la transacción (patrón S4–S6). Integración asserta `orden.creada`/`orden.anulada`. |
| 16 | **Serialización por contrato** | ✅ | `OrdenResource`/`DetalleOrdenResource` bajo `Api/V1` sin fugar `id_establecimiento`; índice paginado. |
| 17 | **Validación en dos capas** | ⚠️ | Forma en 6 Form Requests; estado en servicios con excepciones de dominio. `CrearOrdenRequest` consulta `TipoOrden`/`Mesa` en `withValidator` — acopla el request a modelos. Ver R4. |
| 18 | **Estados centralizados en enum** | ✅ | `EstadoOrden`/`EstadoItem` como fuente única del literal — **cierra la deuda R3 del review del S6** y alinea con `TipoMovimiento` (S5). |
| 19 | **`descuento_item` (renglón) = 0** | ⏭️ | Por diseño: el descuento es a nivel orden (P10); el campo de renglón se reserva para división por ítems (V2). |
| 20 | **Eventos sin listener/assert** | ⚠️ | `OrdenCreada`/`ItemConfirmado`/`OrdenAnulada` se emiten en la transacción, pero sin listener (consumo en S8/S10/S11) y **sin `Event::fake`** en tests. Ver R3 (idéntico a R4 del S6). |

**Pruebas ejecutadas en esta revisión:** `php artisan test` → **158 passed (379 assertions)** en SQLite; `php artisan test -c phpunit.pgsql.xml` → **158 passed** en **PostgreSQL 17**; `pint --test` → `passed`. El Sprint 7 añade **32 pruebas** (6 Unit, 22 Feature, 4 Integration) sobre las 126 del cierre del S6. La corrida pgsql ejercita el índice único parcial `uq_ordenes_abierta_mesa_parcial` como respaldo real de la concurrencia de mesa.

---

## 2. Qué quedó correcto

1. **El ciclo de orden es coherente y atómico.** Crear → agregar/modificar → comanda → descuento → cancelar/anular operan de extremo a extremo, cada uno en su transacción y con auditoría dentro de ella. El `TotalizadorOrden` se mantiene como **calculadora pura** (sin estado, sin BD) y se consume desde un trait `RecalculaTotales` compartido, de modo que el recálculo es idéntico en todos los servicios que mutan la orden.
2. **Impuesto sobre el neto fiel a P11/D1.** El `precio_venta` no incluye impuesto; el impuesto se suma aparte sobre `subtotal − descuento`. El test no se conforma con el unitario: verifica el total congelado end-to-end tras aplicar descuento (92.80), de modo que la decisión D1 queda blindada contra reinterpretaciones.
3. **Inventario intacto (P1) verificado, no asumido.** La prueba de comanda usa un producto `controla_inventario=true` y asserta `movimientos_inventario` = 0: la garantía de que el stock se descuenta **al cobrar** (S8) está comprobada, no implícita.
4. **Doble defensa de la compuerta de caja.** El middleware `caja.abierta` (listo desde el S6) por fin protege rutas reales, y el servicio reverifica: la regla global 5 no depende de un solo punto. Se atendió la recomendación de arranque del review del S6.
5. **Deuda del S6 saldada de paso.** Los estados se centralizan en `EstadoOrden`/`EstadoItem` (R3 del S6), con `esModificable()` como única puerta de mutación; el patrón es consistente con `TipoMovimiento` del S5.
6. **Concurrencia con doble salvaguarda.** "Una orden abierta por mesa" se sostiene con verificación + bloqueo pesimista y, en pgsql, con el índice único parcial; el folio continuo se serializa con bloqueo sobre el establecimiento. Ambas invariantes tienen prueba.

---

## 3. Qué quedó incompleto

### Diferido por diseño (no bloquea el cierre del Sprint 7)
- **Cobro / cierre de orden / descuento de inventario / liberación de mesa al pagar / ticket** — Sprint 8.
- **Flujo de autorización de dos niveles** (operador solicita → admin aprueba) para cancelar/anular, con el enlace `detalle_orden.id_autorizacion` — Sprint 9.
- **Impresión física de la comanda** — Sprint 10 (en S7 se marca `enviado` y se emite `ItemConfirmado`).
- **Exposición de la ocupación de mesa** derivada — diferida (el cliente la deriva de las órdenes abiertas; regla 11).
- **Descuento por ítem / división por ítems** — V2.

### Observaciones a gestionar (no bloqueantes)
- **Eventos sin assert** (`Event::fake`): añadir cobertura al cablear sus listeners en S8/S10/S11 (R3).
- **`withValidator` consulta modelos** en `CrearOrdenRequest`: funciona y da 422 limpio, pero acopla forma con persistencia (R4).
- **Folio: serialización por tenant** y **umbral de 7 dígitos** del padding (R1, R2).

---

## 4. Riesgos

| ID | Riesgo | Severidad | Impacto |
|----|--------|-----------|---------|
| R1 | **Folio serializa la creación de órdenes por tenant.** El `lockForUpdate` sobre el establecimiento garantiza continuidad (P12) pero serializa **toda** alta de orden del tenant (mesa y barra), no solo las de la misma mesa. | Baja | Para el volumen de un bar/restaurante pequeño es irrelevante; en un pico muy alto reduce el paralelismo de aperturas. Es el mismo patrón que `AbrirCajaService`. Si se vuelve un cuello de botella, mover a una secuencia/tabla de correlativos por tenant. |
| R2 | **`proximoCorrelativo` usa `max()` lexicográfico con padding fijo de 6.** Mientras el folio tenga 6 dígitos el orden lexicográfico coincide con el numérico; al cruzar el millón (`1000000`, 7 dígitos) `'999999'` > `'1000000'` lexicográficamente y el siguiente correlativo colisionaría. | Baja | Umbral teórico de 1.000.000 de órdenes por establecimiento (inalcanzable en MVP). Comportamiento heredado del trait `GeneraFolio` (S0), no introducido aquí. Anotado; corregir si se amplía el padding o el horizonte de datos. |
| R3 | **Eventos de orden sin cobertura.** `OrdenCreada`/`ItemConfirmado`/`OrdenAnulada` se despachan pero ningún test lo verifica con `Event::fake`. | Baja | Un futuro listener (descuento de inventario, impresión, reportes) podría romperse sin que la suite avise. Añadir el assert al cablear cada consumo (mismo criterio que R4 del S6). |
| R4 | **`CrearOrdenRequest` consulta `TipoOrden`/`Mesa` en validación.** La coherencia "tipo mesa ⇒ mesa obligatoria y activa" vive en `withValidator`. | Baja | Acopla el Form Request a Eloquent y agrega consultas en la capa de forma. Es legible y da 422 correcto; si crece, mover a una Rule dedicada. Cross-tenant de mesa devuelve 422 "no disponible" (no 404), aceptable para un campo de formulario. |
| R5 | **Aritmética del totalizador en `float`.** `subtotal`/`base`/`impuesto`/`total` se calculan con `round(...,2)` sobre `DECIMAL(12,2)`. | Baja | Para importes normales es seguro; en acumulados grandes podría haber redondeo en el último centavo. Si se exige exactitud estricta, BCMath (mismo criterio que R2 del S6 y R6 del S5). |
| R6 | **Garantía "una orden por mesa" sin índice en SQLite.** El índice único parcial solo existe en PostgreSQL; en SQLite la invariante recae en verificación + bloqueo, sin prueba de carrera real paralela. | Baja | En producción (pgsql) el índice es la última línea; el test cubre el camino secuencial. Igual que R1 del S6. Aceptable, anotado. |

---

## 5. Qué debe corregirse antes del Sprint 8

**Bloqueantes:** ninguno. El Sprint 7 cumple su DoD —ciclo de orden funcional de extremo a extremo, suite verde en SQLite y PostgreSQL, sin migraciones (paridad por construcción), aislamiento probado, policy por entidad, auditoría transaccional, validación en dos capas, totalizador con impuesto sobre el neto, compuerta de caja aplicada— sin defectos ni P0.

**Recomendados (cerrar para no arrastrar deuda, idealmente al cablear los consumidores en S8):**
1. **Añadir asserts de eventos** (`Event::fake` para `OrdenCreada`/`ItemConfirmado`/`OrdenAnulada`) cuando S8 conecte el descuento de inventario y la impresión (R3).
2. **Vigilar el folio** si el volumen crece: documentada la serialización (R1) y el umbral de padding (R2).

**Heredados (deuda planificada):** decisión formal de RLS, divergencia de timestamps, unificación del patrón de auditoría (Observer en S12), aritmética monetaria en float (R5, criterio común con S5/S6).

---

## 6. Conclusión

El **Sprint 7 está bien implementado, es idiomático y está en verde** en ambos motores (158 tests, 379 aserciones, Pint limpio). Lo esencial —el ciclo de orden atómico y auditado, el folio continuo por tenant con doble salvaguarda de concurrencia, el totalizador puro con impuesto sobre el neto verificado end-to-end, la comanda que no toca inventario comprobado contra `movimientos_inventario`, el descuento del cajero sin autorización, y la cancelación/anulación ADMIN directo con OPERADOR a 403— está construido y probado. No hay migraciones, de modo que la paridad de esquema se conserva por construcción, y de paso se centralizan los estados en enums, saldando la deuda R3 del S6.

Las observaciones son honestas y **no bloqueantes**: eventos sin assert (a cubrir cuando lleguen sus listeners), validación que consulta modelos, y propiedades del folio (serialización y umbral de padding) heredadas del trait del S0. Ninguna compromete el inicio del Sprint 8.

---

# Dictamen

## APROBADO PARA SPRINT 8

**Motivo:** la capa de Órdenes está entregada conforme al roadmap y al DoD, verificada en SQLite y PostgreSQL, sin bloqueantes ni P0. El Sprint 8 (Pagos + descuento de inventario al cobrar, M12/M08) tiene sus dependencias satisfechas: **Órdenes (S7)** con su estado `abierta`/`pagada`/`anulada` y el evento de cierre listo para que `OrdenPagada` lo dispare, **Caja (S6)** para el arqueo, **Recetas/Inventario (S5)** para el descuento.

**Recomendación de arranque:** al cablear `OrdenPagada` en S8, conectar el descuento de inventario y la impresión encolada, y aprovechar para añadir los asserts de eventos pendientes (Sección 5, punto 1). El cobro debe cerrar la orden (`pagada`), liberar la mesa por derivación y respetar el stock negativo permitido (P2).
