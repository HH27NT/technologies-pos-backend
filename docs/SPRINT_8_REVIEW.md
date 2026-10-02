# SPRINT_8_REVIEW.md — Revisión Arquitectónica del Sprint 8

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Revisor:** Arquitecto de Software Senior
**Fecha:** 2026-06-19
**Base de evaluación:** `RoadmapImplementacion.md` (Sprint 8, §4 Pruebas §519–522, §5 DoD), `SPRINT_8_ALCANCE.md`, `EspecificacionFuncional.md` (M12, M08 descuento por venta, CU-12, reglas globales), `ArquitecturaBackend.md` (§7–§10, §15), `Convenciones.md`, `DatabaseDictionary.md` (`pagos`, `movimientos_inventario`, `tickets`).
**Objeto:** validar el **Sprint 8 — Pagos, cierre de orden y descuento de inventario al cobrar (M12 + M08)**: cobro simple y dividido, cierre al saldar, descuento de stock disparado por `OrdenPagada`, liberación de mesa por derivación e idempotencia de pagos.

---

## 0. Encuadre de alcance (qué exigía el Sprint 8 y qué se entregó)

El Roadmap define el Sprint 8 como el **cierre del camino crítico de la venta** (completa el paso 6 e introduce el paso 7 de §23): cobrar la orden y disparar el descuento de inventario, la liberación de mesa y la impresión. El alcance entregado coincide con el roadmap y ratifica las dos decisiones que la documentación dejaba abiertas (`SPRINT_8_ALCANCE.md` §1):

- **D1** — idempotencia de pagos con **respaldo en BD**: índice único parcial `(id_orden, referencia) WHERE referencia IS NOT NULL` (pgsql) + verificación en el servicio; un reintento con la misma `referencia` devuelve el pago existente sin recobrar.
- **D2** — el ticket de cobro **solo se emite como evento** (`OrdenPagada`); la generación/impresión real es Sprint 10.

**Hallazgo de encuadre.** El sprint añade **una migración** —la segunda desde el S0, análoga a la D1 del S6—, justificada por la garantía financiera de idempotencia; es un índice, no una columna, así que no altera la forma de la tabla. No se detecta *scope creep*: no se generó el ticket (S10), no se adelantó el flujo de autorización de dos niveles (S9) ni los reportes de medios de pago (S11). La impresión se deja como punto de integración (evento), tal como el roadmap la delega.

---

## 1. Verificación punto por punto

Leyenda: ✅ Correcto · ⚠️ Correcto con observación/riesgo · ⛔ Defecto/Pendiente · ⏭️ Diferido por diseño

| # | Área | Estado | Evidencia |
|---|------|--------|-----------|
| 1 | **Cobro simple** | ✅ | `RegistrarPagoService`: valida orden `abierta`, asienta pago, cierra a `pagada` al saldo 0; emite `OrdenPagada`/`PagoRegistrado`; audita. Test 201 + estado pagada + saldo 0. |
| 2 | **Cobro dividido por monto (P8)** | ✅ | N pagos parciales; el saldo se recalcula como `total − Σ pagos`; cierre al saldar. Test de dos pagos (60+40) y `assertDatabaseCount('pagos', 2)`. |
| 3 | **Idempotencia (D1)** | ⚠️ | `referencia` deduplicada bajo `lockForUpdate` en el servicio; índice único parcial en pgsql. Test: 2º intento devuelve el mismo pago, `idempotente=true`, un solo registro. En SQLite la garantía recae en verificación + bloqueo — ver R1. |
| 4 | **Cierre de orden al saldar** | ✅ | `cerrarOrden` fija `estado=pagada` + `cerrada_at`, audita `orden.pagada` y emite `OrdenPagada` dentro de la transacción. |
| 5 | **No cobrar orden pagada/anulada** | ✅ | Verifica `EstadoOrden::Abierta`; en caso contrario `OrdenNoCobrableException` (422). Test de 2º cobro sobre orden ya pagada → 422. |
| 6 | **Sobrepago: efectivo → cambio** | ✅ | El monto aplicado y **almacenado** es `min(monto, saldo)`; `cambio = monto − aplicado` se devuelve y **no** se persiste (arqueo cuadra). Test de 150 sobre saldo 100 → cambio 50, pago 100. |
| 7 | **Sobrepago: tarjeta/transferencia → 422** | ✅ | `monto > saldo` en no-efectivo lanza `SobrepagoNoPermitidoException` (422). Test con tarjeta → 422. |
| 8 | **Sin propina (P9)** | ✅ | Ningún proceso escribe `pagos.propina`; test asserta `propina` nula tras el cobro. |
| 9 | **Descuento de inventario al cobrar (P1)** | ✅ | Listener síncrono `DescontarInventario` sobre `OrdenPagada`, **dentro de la transacción** del cobro; recorre recetas de los renglones activos. Integración: stock 10 → 4 con receta 2×3. |
| 10 | **Stock negativo permitido (P2)** | ✅ | La venta no se bloquea por stock; `stock_resultante` puede ser negativo. Test stock 1, consumo 5 → −4. |
| 11 | **Producto sin receta / sin control** | ✅ | `controla_inventario=false` o sin receta → no genera movimiento (contrato S4). Test asserta 0 movimientos. |
| 12 | **Liberación de mesa (regla 11)** | ✅ | Por **derivación**: al pasar a `pagada` la mesa deja de tener orden abierta. Sin flag persistido. Test: `mesa.ordenAbierta()` pasa de no-null a null tras el cobro. |
| 13 | **Reversa solo de `venta` (P3)** | ✅ | `RevertirMovimientoService` compensa únicamente movimientos `venta`; ignora entrada/ajuste/merma. Unit: revierte 1 de 2, stock +3 (solo la venta). |
| 14 | **Atomicidad financiera (§15)** | ✅ | Pago + cierre + descuento + auditoría en una sola transacción (el listener es síncrono, no `ShouldQueue`). La impresión se delega al evento (no bloquea). |
| 15 | **Compuerta de caja en el cobro** | ✅ | `POST /pagos` bajo `caja.abierta`; test de cobro con caja cerrada → 409. El saldo (lectura) no exige caja. |
| 16 | **Policy (matriz Fase 7)** | ✅ | `OrdenPolicy::cobrar` → `ordenes.cobrar` (ADMIN+OPERADOR, ya sembrado). |
| 17 | **Aislamiento multi-tenant** | ✅ | Pagos, saldo y movimientos por `TenantScope`; orden de otro tenant → 404. |
| 18 | **Migración D1 + paridad** | ✅ | Índice parcial portable; suite verde en ambos motores y **ciclo `up()`/`down()`/`migrate:fresh` verificado en pgsql** (índice creado, rollback lo elimina, se recrea). |
| 19 | **Doble dispatch del descuento** | ✅ | **Defecto detectado y corregido en implementación** (ver §2.6 y R2): el listener se registraba dos veces (auto-discovery de Laravel 11 + registro explícito), duplicando el descuento. Resuelto eliminando el registro explícito. |
| 20 | **Eventos sin assert** | ⚠️ | `PagoRegistrado`/`OrdenPagada` se verifican **indirectamente** (el descuento prueba que `OrdenPagada` se consume), pero no hay `Event::fake` directo. Ver R3 (hilo abierto desde S6/S7). |

**Pruebas ejecutadas en esta revisión:** `php artisan test` → **176 passed (425 assertions)** en SQLite; `php artisan test -c phpunit.pgsql.xml` → **176 passed** en **PostgreSQL 17**; `pint --test` → `passed`; ciclo `migrate:fresh` + `migrate:rollback` + `migrate` → limpio en pgsql, con el índice parcial creado/eliminado/recreado. El Sprint 8 añade **18 pruebas** (6 Unit, 7 Feature, 5 Integration) sobre las 158 del cierre del S7.

---

## 2. Qué quedó correcto

1. **El cobro cierra el camino crítico con atomicidad real.** Pago + cierre de orden + descuento de inventario + auditoría financiera viven en una sola transacción; el descuento es un listener **síncrono** (no encolado a propósito), de modo que si el cobro se revierte, el stock también. La impresión se delega al evento y nunca bloquea el cobro.
2. **El descuento de inventario reusa el ledger del S5 sin reescribirlo.** Asienta movimientos `venta` con `stock_resultante`, respeta el `controla_inventario`/receta (contrato S4) y permite negativo (P2) —el comportamiento opuesto a la salida manual del S5, que sí se bloquea—, cada uno verificado con su prueba.
3. **Idempotencia con doble defensa y arqueo coherente.** La `referencia` deduplica bajo bloqueo y el índice parcial en pgsql es la última salvaguarda; el sobrepago en efectivo almacena solo lo aplicado, de modo que el arqueo del S6 (que ya apuntaba a `pagos`) cuadra sin tocar nada.
4. **Liberación de mesa por derivación, sin deuda de estado.** No se introdujo ningún flag `ocupada`: pagar quita la orden abierta y la mesa queda libre, fiel a la regla global 11 y al contrato que el S4/S7 prepararon.
5. **El arqueo del S6 cobra valor sin cambios.** `CerrarCajaService` sumaba `pagos ⋈ ordenes` en efectivo dando 0; al escribir pagos reales el arqueo funciona de extremo a extremo, confirmando que el cableado del S6 era correcto.
6. **Disciplina ante un defecto de framework.** Durante la implementación el descuento se aplicaba **dos veces**: Laravel 11 auto-descubre los listeners de `app/Listeners` con método `handle()`, y además se había registrado explícitamente en `AppServiceProvider`. Se diagnosticó inspeccionando los listeners crudos de `OrdenPagada` (aparecía dos veces) y se resolvió eliminando el registro redundante, dejando una nota para evitar la recaída. El caso quedó cubierto por las pruebas de integración (stock exacto, no doble).

---

## 3. Qué quedó incompleto

### Diferido por diseño (no bloquea el cierre del Sprint 8)
- **Generación/impresión del ticket de cobro** (registro en `tickets`, `contenido_json`, PDF fallback) — Sprint 10 (D2).
- **Flujo de autorización de dos niveles** (cancelar/anular por operador, entrada/ajuste) — Sprint 9.
- **Reporte de medios de pago, cancelaciones, margen** — Sprint 11.
- **Propina** — reservada V2 (P9).

### Observaciones a gestionar (no bloqueantes)
- **`RevertirMovimientoService` sin cablear.** Se entrega como salvaguarda probada por unidad (el roadmap lo exige), pero no lo invoca ningún flujo de usuario en S8 porque cancelar/anular ocurren pre-cobro. Quedará conectado si S9 introduce una anulación post-cobro (R4).
- **Eventos sin `Event::fake` directo** (R3).
- **Idempotencia sin índice en SQLite** (R1).

---

## 4. Riesgos

| ID | Riesgo | Severidad | Impacto |
|----|--------|-----------|---------|
| R1 | **Idempotencia sin índice en SQLite.** El índice único parcial solo existe en PostgreSQL; en SQLite la garantía recae en la verificación + `lockForUpdate` del servicio, sin prueba de carrera real paralela. | Baja | En producción (pgsql) el índice es la última línea ante un doble POST simultáneo con la misma `referencia`; el test cubre el reintento secuencial. Mismo criterio que el índice parcial de "una orden abierta por mesa" (S7 R6). |
| R2 | **Auto-discovery de listeners de Laravel 11.** Cualquier clase en `app/Listeners` con `handle(Evento)` se registra automáticamente; un registro explícito adicional **duplica** el efecto (ocurrió con el descuento y se corrigió). | Media | Ya resuelto y anotado, pero es una trampa latente: un futuro listener registrado "por las dudas" en `AppServiceProvider` volvería a duplicar. Convención a respetar: listeners con `handle()` NO se registran a mano; los subscribers con métodos de nombre propio (como `RegistrarAuditoria`) sí. Cubierto por las pruebas de integración (stock exacto). |
| R3 | **Eventos de pago sin assert directo.** `PagoRegistrado` no se verifica con `Event::fake`; `OrdenPagada` se prueba solo de forma indirecta (vía el descuento). | Baja | Un futuro consumidor (impresión S10, reportes S11) podría romperse sin que la suite avise para `PagoRegistrado`. Añadir el assert al cablear cada consumo (hilo abierto desde S6 R4 / S7 R3). |
| R4 | **`RevertirMovimientoService` no integrado.** Existe y se prueba, pero ningún flujo lo llama en S8. | Baja | Código en reposo; su corrección depende de que S9 (o una anulación post-cobro) lo conecte. Sin impacto funcional hoy; anotado para que no se olvide su cableado. |
| R5 | **Aritmética de saldo/cambio en `float`.** `saldo`/`aplicado`/`cambio` se calculan con `round(...,2)` sobre `DECIMAL(12,2)`. | Baja | Para importes normales es seguro; en acumulados grandes podría haber redondeo en el último centavo. Si se exige exactitud estricta, BCMath (criterio común S5 R6 / S6 R2 / S7 R5). |
| R6 | **`DescontarInventarioService` abre transacción anidada.** Corre dentro de la transacción del cobro y además envuelve su lógica en `DB::transaction` (savepoint). | Baja | Correcto en pgsql y SQLite (la suite lo confirma), pero el savepoint es redundante dado que ya está dentro de una transacción; podría simplificarse. Sin efecto observable. |

---

## 5. Qué debe corregirse antes del Sprint 9

**Bloqueantes:** ninguno. El Sprint 8 cumple su DoD —cobro simple y dividido de extremo a extremo, cierre al saldar, descuento de inventario al cobrar con stock negativo permitido, liberación de mesa por derivación, idempotencia con respaldo en BD, suite verde en SQLite y PostgreSQL, migración con ciclo up/down/fresh verificado, aislamiento probado, policy, auditoría transaccional, validación en dos capas— sin defectos abiertos ni P0. El único defecto detectado (doble descuento) se corrigió dentro del sprint.

**Recomendados (cerrar para no arrastrar deuda):**
1. **Añadir asserts de eventos** (`Event::fake` para `PagoRegistrado`/`OrdenPagada`) al cablear sus consumidores en S10/S11 (R3).
2. **Conectar `RevertirMovimientoService`** cuando S9 defina la anulación post-cobro, o documentarlo formalmente como salvaguarda en reposo (R4).
3. **Vigilar el auto-discovery** al añadir nuevos listeners: no registrar a mano los que tengan `handle()` (R2).

**Heredados (deuda planificada):** decisión formal de RLS, aritmética monetaria en float (R5), unificación del patrón de auditoría (Observer en S12), riesgos del folio (S7 R1/R2).

---

## 6. Conclusión

El **Sprint 8 está bien implementado, es idiomático y está en verde** en ambos motores (176 tests, 425 aserciones, Pint limpio). Lo esencial —el cobro atómico que cierra la orden, el descuento de inventario al cobrar disparado por `OrdenPagada` dentro de la misma transacción, el stock negativo permitido verificado, la idempotencia con doble defensa y arqueo coherente, y la liberación de mesa por derivación sin deuda de estado— está construido y probado. La única migración se justifica por la garantía financiera de idempotencia y se verificó su ciclo completo en PostgreSQL, preservando la paridad.

El defecto de doble descuento (auto-discovery + registro explícito) se detectó y corrigió durante el sprint, y quedó blindado por las pruebas de integración que afirman el stock exacto. Las observaciones restantes son honestas y **no bloqueantes**: idempotencia sin índice en SQLite, eventos sin assert directo, la salvaguarda de reversa en reposo y la aritmética en float. Ninguna compromete el inicio del Sprint 9.

---

# Dictamen

## APROBADO PARA SPRINT 9

**Motivo:** la capa de Pagos y el descuento de inventario al cobrar están entregados conforme al roadmap y al DoD, verificados en SQLite y PostgreSQL, sin bloqueantes ni P0. El Sprint 9 (Autorizaciones de dos niveles, M14) tiene sus dependencias satisfechas: **Órdenes (S7)** con cancelar/anular ya operativos como ADMIN directo, **Inventario (S5/S8)** con entrada/ajuste y el ledger, y los `id_autorizacion` nullable ya presentes en `detalle_orden` y `movimientos_inventario` desde el S0.

**Recomendación de arranque:** materializar "operador solicita → admin aprueba → sistema ejecuta el servicio destino" reutilizando los servicios ya construidos (`CancelarItemService`, `AnularOrdenService`, `RegistrarMovimientoService`), enlazando el resultado vía `id_autorizacion`; y cerrar la rama del operador en `OrdenPolicy`/`MovimientoInventarioPolicy` de modo que el operador nunca tenga el permiso directo. Aprovechar para añadir los asserts de eventos pendientes (Sección 5, punto 1).
