# ARQUITECTURA BACKEND — SaaS POS Multi-Tenant (MVP V1)

**Stack:** Laravel 12 · PostgreSQL 15+ · Laravel Sanctum · spatie/laravel-permission · Cola de trabajos (database/Redis)
**Patrones:** Service Layer · Policies · Domain Events + Listeners · Queued Jobs · Multi-tenant por `id_establecimiento` · Auditoría append-only
**Base:** DER V1.2 (22 tablas; ya incorpora los ajustes de §2.1) + Especificación Funcional MVP V1 + **respuestas Fase 9 confirmadas**
**Alcance:** documento de arquitectura listo para iniciar desarrollo. **No contiene código.**

---

## 1. PRINCIPIOS DE DISEÑO

1. **Aislamiento por tenant primero.** Toda entidad operativa se filtra automáticamente por `id_establecimiento`; el aislamiento no depende de que el programador lo recuerde.
2. **La lógica de negocio vive en Servicios.** Controllers orquestan (validan, delegan, responden). Models definen datos y relaciones. Servicios ejecutan reglas dentro de transacciones.
3. **Toda operación sensible es transaccional y auditable.** Una acción de negocio = una transacción + un evento de dominio + un registro de auditoría.
4. **Las reglas volátiles se aíslan.** El punto de descuento de inventario, el cálculo de impuesto y el arqueo se concentran en un único servicio cada uno, para poder ajustarlos sin tocar todo el sistema.
5. **Estado de negocio garantizado en la base, no solo en la app.** "Una caja abierta", "una orden abierta por mesa" se respaldan con índices únicos parciales y bloqueos.

---

## 2. DECISIONES DE LA FASE 9 (CONFIRMADAS)

Estas decisiones están embebidas en el diseño. Donde una respuesta fijó el comportamiento, se indica el punto exacto del sistema que la implementa.

| Ref | Decisión confirmada | Dónde se implementa |
|---|---|---|
| **P1** | Inventario se descuenta **al COBRAR** la orden. | Evento `OrdenPagada` → `DescontarInventarioService`. **No** se descuenta al enviar comanda. |
| **P2** | Stock insuficiente **no bloquea**; se permite negativo + alerta. | `DescontarInventarioService` registra el movimiento y emite `StockBajoDetectado`. |
| **P3** | Cancelación de **descuento automático** → revierte automático. **Ajuste manual** → no revierte. | `RevertirMovimientoService` solo actúa sobre movimientos de origen `venta`; nunca sobre entrada/ajuste/merma. (Ver consecuencia clave en §13.) |
| **P4** | Unidades de medida: **predefinidas (seed) + el establecimiento puede crear las suyas**. | `unidades_medida` pasa a híbrido (global + por tenant). **Implica ajuste al DER** (§2.1). |
| **P5** | Cierre con diferencia: **registrar + motivo obligatorio, SIN autorización**. | `CerrarCajaService` exige `motivo` si `diferencia ≠ 0`; no genera autorización. |
| **P6** | Arqueo: **solo efectivo**. Tarjeta y transferencia → solo reporte. | `CerrarCajaService` calcula `monto_sistema` con pagos en efectivo únicamente. |
| **P7** | **No** hay entradas/salidas de efectivo de caja (retiros/fondos). | `monto_sistema = monto_inicial + ventas en efectivo` (sin término de salidas). |
| **P8** | Pago dividido **por monto**. | `RegistrarPagoService` acepta N pagos parciales hasta saldar. |
| **P9** | Propina **no se registra** (es del mesero). | `pagos.propina` **queda sin uso** en V1 (§2.1). Servicios y tickets la ignoran. |
| **P10** | Descuentos: los aplica **el cajero**, sin autorización. | Permiso `ordenes.aplicar_descuento` para admin y operador; sin flujo de autorización. |
| **P11** | Impuesto **configurable por establecimiento** y se **suma aparte** (precio sin impuesto). | `TotalizadorOrden` lee la tasa de `ConfiguracionEstablecimiento`. **Implica ajuste al DER** (§2.1). |
| **P12** | Folio: **secuencia continua por establecimiento** (no reinicia). | `GeneraFolio` (concern) toma el siguiente correlativo por tenant. |
| **P13** | **No** hay devoluciones/reembolsos en V1. | Fuera de alcance; ninguna ruta/servicio de reembolso. |
| **P14** | Reimpresión **no requiere autorización**. | Reimpresión permitida a admin y operador; se audita por buena práctica, no se bloquea. |
| **P15** | Sin impresora configurada → **se exporta a PDF**. | `GenerarTicketService`/`GenerarComandaService` generan PDF como fallback. |
| **P17** | SUPER_ADMIN **puede impersonar** un establecimiento para soporte. | Middleware de tenant permite override explícito y **auditado**. |
| **P18** | La sesión **no caduca** por inactividad. | Tokens Sanctum sin expiración por inactividad (nota de seguridad en §7). |
| **P19** | Al desactivar un establecimiento con caja/órdenes abiertas → **se bloquean las órdenes**. | `EnsureTenantActivo` bloquea endpoints operativos; las órdenes abiertas quedan congeladas hasta reactivar. |
| **P21** | El OPERADOR ve reportes **solo de su turno/caja**. | `ReportePolicy` + scope por `sesion_caja` del operador. |

### Pendientes que el equipo respondió (cierre de documentación)
- **P16 (política de contraseñas / intentos fallidos): RESUELTO.** Longitud mínima de 8 caracteres, **sin** bloqueo por intentos fallidos. Implementado como configurable. (Ver §7 y §5.1 de Convenciones.)
- **P20 (fuente del costo en el reporte de margen): RESUELTO.** Por producto: `controla_inventario = false` → `costo_referencia`; `controla_inventario = true` → costo de insumos de la receta. Implementado como configurable (§16).

### 2.1 Implicaciones sobre el DER (YA incorporadas en el DER V1.2)

Estas respuestas exigieron pequeños ajustes que **el DER V1.2 y el Diccionario V1.2 ya incorporan** (no cambian la intención del modelo; la completan). Se conservan documentados aquí como trazabilidad:

1. **`unidades_medida` deja de ser catálogo global puro (P4).** Debe llevar `id_establecimiento` **NULL = unidad predefinida (seed, visible para todos)**; **no nula = unidad propia del establecimiento**. La consulta combina ambas (global + propias).
2. **`configuracion_establecimiento` necesita campos de impuesto (P11):** `aplica_impuesto BOOLEAN` y `tasa_impuesto DECIMAL(5,2)`. El `precio_venta` se mantiene sin impuesto; el `TotalizadorOrden` lo suma según esta tasa.
3. **`pagos.propina` queda sin uso (P9).** Se conserva la columna para V2; ningún servicio la escribe ni la reporta en V1. (Alternativa: eliminarla del DER; recomiendo conservarla para no romper el modelo aprobado.)
4. **`detalle_orden` gana `enviado` (BOOLEAN, default `false`).** Necesario para la regla "modificar cantidad solo de ítems no enviados": `AgregarItemService` solo permite editar la cantidad mientras `enviado = false`; `ConfirmarComandaService` lo pone en `true`. Ya incorporado en el DER/Diccionario V1.2.

---

## 3. ESTRUCTURA DE CARPETAS

Estructura modular orientada a dominio, idiomática para el esqueleto reducido de Laravel 12 (middleware y providers en `bootstrap/`).

```
app/
├── Models/                      # Eloquent puro (datos + relaciones), uno por tabla del DER
│   ├── Establecimiento.php          ConfiguracionEstablecimiento.php
│   ├── Usuario.php                  SesionCaja.php
│   ├── Mesa.php                     TipoOrden.php (global)
│   ├── Orden.php                    DetalleOrden.php
│   ├── TipoPago.php (global)        Pago.php
│   ├── Ticket.php                   Impresora.php
│   ├── CategoriaProducto.php        Producto.php
│   ├── UnidadMedida.php (híbrido)   Proveedor.php
│   ├── Insumo.php                   RecetaProducto.php
│   ├── MovimientoInventario.php     Autorizacion.php
│   └── Auditoria.php
│
├── Domain/                      # Lógica de negocio por módulo
│   ├── Caja/{Services,Events,DataObjects}/
│   ├── Ordenes/{Services,Events,DataObjects}/
│   ├── Pagos/{Services,Events}/
│   ├── Inventario/{Services,Events}/
│   ├── Productos/Services/
│   ├── Autorizaciones/{Services,Events}/
│   ├── Establecimientos/Services/
│   ├── Usuarios/Services/
│   ├── Impresion/Services/
│   ├── Reportes/{Queries,Exporters}/
│   └── Auditoria/Services/
│
├── Http/
│   ├── Controllers/Api/V1/      # Controllers delgados por recurso
│   ├── Requests/                # Form Requests (validación, §17)
│   ├── Resources/               # API Resources (salida)
│   └── Middleware/              # ResolveTenant, EnsureTenantActivo, EnsureCajaAbierta
│
├── Policies/                    # Una policy por modelo gobernado (§9)
├── Listeners/                   # Auditar, descontar/revertir stock, imprimir, liberar mesa
├── Jobs/                        # Trabajos en cola (imprimir/PDF, exportar reportes)
├── Support/
│   ├── Tenant/                  # TenantContext, TenantScope, BelongsToTenant, IncluyeGlobales
│   ├── Concerns/                # Auditable, GeneraFolio
│   └── Exceptions/              # Excepciones de dominio (§20)
│
bootstrap/
├── app.php                      # Middleware (alias y grupos) + handler de excepciones
└── providers.php

database/{migrations,seeders,factories}/
```

---

## 4. MÓDULOS (mapeo a la especificación funcional)

| Módulo dominio | Módulos funcionales | Servicios principales |
|---|---|---|
| Establecimientos | M02, M03 | CrearEstablecimientoService, ActualizarConfiguracionService |
| Usuarios | M01, M04 | CrearUsuarioService, AsignarRolService |
| Catálogo | M05, M06, M07 | GuardarProductoService, GestionarRecetaService |
| Inventario | M08 | RegistrarMovimientoService, DescontarInventarioService, RevertirMovimientoService |
| Mesas | M09 | (CRUD + estado derivado) |
| Caja | M10 | AbrirCajaService, CerrarCajaService |
| Órdenes | M11 | CrearOrdenService, AgregarItemService, CancelarItemService, AnularOrdenService, TotalizadorOrden |
| Pagos | M12 | RegistrarPagoService |
| Impresión | M13 | GenerarTicketService, GenerarComandaService |
| Autorizaciones | M14 | SolicitarAutorizacionService, ResolverAutorizacionService |
| Auditoría | M15 | RegistrarAuditoriaService |
| Reportes | M16 | Query services + Exporters |

---

## 5. MODELOS ELOQUENT

Convenciones: PK `BIGINT`; `SoftDeletes` donde el DER define `deleted_at`; casts de `JSONB`→array, montos→`decimal:2/3`, timestamps→`datetime`, booleanos→`bool`. Sin lógica de negocio en los modelos.

**Traits:** `BelongsToTenant` (agrega `id_establecimiento`, aplica `TenantScope`, autollena al crear), `Auditable` (captura antes/después vía Observer), `SoftDeletes`.

**Modelos NO tenant (globales):** `TipoOrden`, `TipoPago`, `Establecimiento` (raíz) y roles/permisos de Spatie. **`UnidadMedida` es híbrido** (ver §6 y §7): incluye filas globales (tenant nulo) + propias del establecimiento.

| Modelo | Tabla | Traits |
|---|---|---|
| Establecimiento | establecimientos | SoftDeletes, Auditable |
| ConfiguracionEstablecimiento | configuracion_establecimiento | Auditable |
| Usuario | usuarios | BelongsToTenant*, SoftDeletes, Auditable, HasApiTokens, HasRoles |
| SesionCaja | sesiones_caja | BelongsToTenant |
| Mesa | mesas | BelongsToTenant, SoftDeletes, Auditable |
| TipoOrden | tipos_orden | — (global) |
| Orden | ordenes | BelongsToTenant |
| DetalleOrden | detalle_orden | BelongsToTenant |
| TipoPago | tipos_pago | — (global) |
| Pago | pagos | BelongsToTenant |
| Ticket | tickets | BelongsToTenant |
| Impresora | impresoras | BelongsToTenant, SoftDeletes |
| CategoriaProducto | categorias_producto | BelongsToTenant, SoftDeletes |
| Producto | productos | BelongsToTenant, SoftDeletes, Auditable |
| UnidadMedida | unidades_medida | IncluyeGlobales (scope híbrido) |
| Proveedor | proveedores | BelongsToTenant, SoftDeletes |
| Insumo | insumos | BelongsToTenant, SoftDeletes, Auditable |
| RecetaProducto | recetas_producto | BelongsToTenant |
| MovimientoInventario | movimientos_inventario | BelongsToTenant (append-only) |
| Autorizacion | autorizaciones | BelongsToTenant |
| Auditoria | auditoria | — (tenant nullable) |

\* El super_admin tiene `id_establecimiento = NULL` y queda exento del scope (§7).

---

## 6. RELACIONES ELOQUENT

```
Establecimiento  hasOne ConfiguracionEstablecimiento; hasMany Usuario, Mesa, Orden,
                 SesionCaja, Producto, CategoriaProducto, Insumo, Proveedor, Impresora,
                 Pago, Ticket, Autorizacion, UnidadMedida(propias)
Usuario          belongsTo Establecimiento; hasMany Orden, Pago, Ticket, MovimientoInventario,
                 SesionCaja(apertura); roles() [Spatie, scoped por team=establecimiento]
SesionCaja       belongsTo Establecimiento, Usuario(apertura), Usuario(cierre); hasMany Orden
Mesa             belongsTo Establecimiento; hasMany Orden; ordenAbierta() [hasOne estado='abierta']
Orden            belongsTo Establecimiento, SesionCaja, Mesa(nullable), TipoOrden, Usuario;
                 hasMany DetalleOrden, Pago, Ticket, MovimientoInventario
DetalleOrden     belongsTo Orden, Producto, Autorizacion(nullable)
Pago             belongsTo Orden, TipoPago, Usuario, Establecimiento
Ticket           belongsTo Orden, Usuario, Impresora(nullable), Establecimiento
CategoriaProducto belongsTo Establecimiento; hasMany Producto
Producto         belongsTo Establecimiento, CategoriaProducto; hasMany DetalleOrden, RecetaProducto;
                 insumos() [belongsToMany Insumo through recetas_producto, con cantidad]
Insumo           belongsTo Establecimiento, UnidadMedida, Proveedor(nullable);
                 hasMany RecetaProducto, MovimientoInventario
MovimientoInventario belongsTo Insumo, Usuario, Orden(nullable), Autorizacion(nullable)
Autorizacion     belongsTo Establecimiento, Usuario(solicita), Usuario(autoriza, nullable);
                 referencia polimórfica (entidad + entidad_id)
Auditoria        belongsTo Establecimiento(nullable), Usuario(nullable)
```

---

## 7. ESTRATEGIA MULTI-TENANT

Base y esquema compartidos con discriminador `id_establecimiento`. Aislamiento en tres capas:

1. **Resolución (`ResolveTenant`).** Tras `auth:sanctum`, fija `id_establecimiento` del usuario en `TenantContext` (singleton). El SUPER_ADMIN (tenant nulo) no fija contexto salvo impersonación.
2. **Scope automático (`BelongsToTenant` + `TenantScope`).** Global scope que añade `WHERE id_establecimiento = TenantContext::id()` y autollena el tenant al crear.
3. **Respaldo en BD.** Índices por `id_establecimiento`, unicidades compuestas por tenant (folio, número de mesa, login) y, recomendado, **Row-Level Security** de PostgreSQL.

**`UnidadMedida` (híbrido, P4).** Usa el scope `IncluyeGlobales`: las consultas devuelven `id_establecimiento = TenantContext::id() OR id_establecimiento IS NULL`. Las filas nulas son las predefinidas (seed, no editables por el tenant); las propias se crean con el tenant actual.

**SUPER_ADMIN e impersonación (P17).** `id_establecimiento = NULL`, exento del scope. Para soporte, el middleware acepta fijar un tenant objetivo (cabecera validada contra su rol); la sesión queda marcada como **impersonación** y cada acción se audita como tal.

**Sesión (P18).** Tokens Sanctum **sin expiración por inactividad**. Nota de seguridad: al no caducar, se recomienda compensar con revocación explícita de tokens al desactivar usuario/establecimiento y, opcionalmente, rotación manual; queda como decisión de negocio asumida.

**Desactivación de establecimiento (P19).** `EnsureTenantActivo` bloquea todos los endpoints operativos del tenant inactivo; las órdenes abiertas quedan **congeladas** (no se pueden modificar ni cobrar) hasta reactivar. No hay cierre forzado.

**Catálogos globales puros:** `TipoOrden`, `TipoPago` (sin tenant, compartidos).

---

## 8. ESTRATEGIA DE PERMISOS (spatie/laravel-permission)

**Autoridad:** Spatie. **Reconciliación con el DER (a ratificar):** alinear la tabla `roles` del DER con la de Spatie (`name`, `guard_name`, `team_id`) y conservar `usuarios.id_rol` como **cache del rol principal**; las asignaciones reales viven en `model_has_roles`.

**Scoping por tenant:** función **teams** de Spatie con `team_foreign_key = id_establecimiento`. Ser ADMIN del establecimiento A no otorga nada en el B. El SUPER_ADMIN es rol **global** (sin team), con `Gate::before` que le concede todo.

**Roles semilla:** `super_admin`, `admin`, `operador`.

**Catálogo de permisos (matriz Fase 7 + Fase 9):**
```
establecimientos.gestionar  establecimientos.activar  establecimientos.asignar_admin
metricas.globales  auditoria.global
configuracion.editar  usuarios.gestionar
categorias.gestionar  productos.gestionar  recetas.gestionar
insumos.gestionar  proveedores.gestionar  mesas.gestionar  impresoras.gestionar
unidades.gestionar                         # crear unidades propias (P4)
caja.abrir  caja.cerrar
ordenes.crear  ordenes.agregar_item  ordenes.cobrar  ordenes.aplicar_descuento   # (P10)
tickets.imprimir  tickets.reimprimir         # reimprimir SIN autorización (P14)
inventario.merma  inventario.entrada  inventario.ajustar
ordenes.cancelar_item  ordenes.anular
autorizaciones.aprobar
reportes.ver  reportes.ver_limitado          # operador: solo su turno/caja (P21)
auditoria.ver
```

**"🔐 requiere autorización" = flujo de dos niveles** (no es un permiso especial). El OPERADOR posee el permiso de *solicitar*; el ADMIN el de *ejecutar/aprobar*. Al aprobar, el sistema ejecuta bajo la potestad del ADMIN. El OPERADOR nunca tiene `ordenes.cancelar_item`, `ordenes.anular`, `inventario.entrada` ni `inventario.ajustar` de forma directa. Detalle en §14.

---

## 9. POLICIES

Una policy por modelo; los métodos mapean a `can('permiso')`. La autorización de *acceso* va en policies; las reglas de *estado de negocio* (caja abierta, mesa ocupada) van en los servicios.

| Policy | Métodos | Regla |
|---|---|---|
| EstablecimientoPolicy | viewAny, create, update, toggle | solo super_admin |
| UsuarioPolicy | viewAny, create, update, delete | admin; no auto-baja del único admin (en servicio) |
| ConfiguracionPolicy | update | admin |
| Categoria/Producto/Insumo/Proveedor/Mesa/ImpresoraPolicy | viewAny, create, update, delete | admin |
| UnidadMedidaPolicy | create, update | admin (solo sobre filas propias, no las globales) |
| SesionCajaPolicy | abrir, cerrar | admin y operador |
| OrdenPolicy | create, addItem, cobrar, aplicarDescuento | admin y operador |
| OrdenPolicy | cancelarItem, anular | admin directo; operador solo vía solicitud |
| MovimientoInventarioPolicy | merma | admin y operador |
| MovimientoInventarioPolicy | entrada, ajustar | admin directo; operador solo vía solicitud |
| TicketPolicy | imprimir, reimprimir | ambos roles, sin autorización (P14) |
| AutorizacionPolicy | resolver | solo admin |
| ReportePolicy | ver (completo / limitado) | admin: establecimiento; operador: su turno/caja (P21) |
| AuditoriaPolicy | ver | admin (tenant), super_admin (global) |

`Gate::before` → super_admin pasa todas.

---

## 10. MIDDLEWARE

Registrado en `bootstrap/app.php`.

| Middleware | Responsabilidad | Orden |
|---|---|---|
| `auth:sanctum` | autenticar | 1 |
| `ResolveTenant` | fijar `TenantContext` (o impersonación super_admin) | 2 |
| `EnsureTenantActivo` | bloquear si el establecimiento está desactivado (P19) | 3 |
| `role:` / `permission:` (Spatie) | autorización gruesa por ruta | 4 |
| `EnsureCajaAbierta` | solo en rutas de venta: exige sesión de caja abierta | 5 |

Las policies actúan después, a nivel de acción/recurso.

## 11. SERVICE LAYER

Cada servicio: única responsabilidad, recibe un DTO, **envuelve su lógica en `DB::transaction`**, valida estado de negocio, persiste y **emite eventos**. Los Controllers solo autorizan, arman el DTO y devuelven un Resource.

**Establecimientos**
- `CrearEstablecimientoService` — crea establecimiento + `ConfiguracionEstablecimiento` (incluye `aplica_impuesto`/`tasa_impuesto`) + unidades de medida semilla propias si se desea + usuario ADMIN inicial + rol (team). Emite `EstablecimientoCreado`.
- `ActualizarConfiguracionService` — edita datos de ticket, impresión automática, stock mínimo global, **tasa de impuesto** (P11).

**Usuarios**
- `CrearUsuarioService` / `ActualizarUsuarioService` — unicidad por tenant; sincroniza rol Spatie + cache `id_rol`; **impide desactivar al único admin activo**. Emite `UsuarioCreado` / `UsuarioModificado`.

**Caja**
- `AbrirCajaService` — `lockForUpdate` sobre el establecimiento; verifica que no exista sesión abierta; crea sesión `abierta` con `monto_inicial`. Emite `CajaAbierta`.
- `CerrarCajaService` — verifica ausencia de órdenes abiertas; **`monto_sistema = monto_inicial + Σ pagos en efectivo`** (P6, P7: sin retiros ni otros medios); recibe `monto_contado`; calcula `diferencia`; **exige `motivo` si `diferencia ≠ 0`** (P5); **no genera autorización**; cierra sesión. Tarjeta/transferencia se acumulan solo para reporte. Emite `CajaCerrada`.

**Órdenes**
- `CrearOrdenService` — exige caja abierta; si es de mesa, `lockForUpdate` sobre la mesa y valida sin orden abierta; genera folio con `GeneraFolio` (**correlativo continuo por tenant**, P12); crea orden `abierta`. Emite `OrdenCreada`.
- `AgregarItemService` — valida orden abierta y producto disponible; crea/actualiza `DetalleOrden`; **solo permite modificar la cantidad de ítems con `enviado = false`**; recalcula con `TotalizadorOrden`. Emite `ItemAgregado`.
- `ConfirmarComandaService` — marca ítems enviados (`enviado = true`); **no toca inventario** (P1). Emite `ItemConfirmado` (solo dispara comanda/impresión).
- `AplicarDescuentoService` — el cajero aplica descuento a ítem u orden, sin autorización (P10); recalcula totales.
- `CancelarItemService` — ejecuta si hay autorización aprobada (o actor admin); marca ítem `cancelado` + `cancelado_at` + `id_autorizacion`; recalcula. Emite `ItemCancelado`.
- `AnularOrdenService` — análogo; marca orden `anulada`; libera mesa. Emite `OrdenAnulada`.
- `TotalizadorOrden` — recalcula subtotal − descuentos + **impuesto (tasa de la configuración, sumado aparte, P11)** = total; "congela" importes en la orden.

**Pagos**
- `RegistrarPagoService` — valida orden abierta y monto > 0; registra `Pago` (tipo, monto, referencia; **sin propina**, P9); recalcula saldo; **soporta pago dividido por monto** (P8); **al llegar saldo a 0**: cierra orden `pagada` y emite `OrdenPagada`. Emite `PagoRegistrado`.

**Inventario**
- `DescontarInventarioService` — **disparado al cobrar** (P1): recorre las recetas de los productos de la orden y registra movimientos `venta` por insumo, solo si el producto `controla_inventario` y tiene receta; permite negativo (P2); emite `StockBajoDetectado` si corresponde.
- `RegistrarMovimientoService` — movimientos manuales (entrada/ajuste/merma/rotura/consumo); append-only; actualiza `stock_actual` y `stock_resultante`; entrada/ajuste por operador requieren autorización previa. Emite `MovimientoRegistrado`.
- `RevertirMovimientoService` — genera movimiento inverso **solo de movimientos de origen `venta`** (P3); nunca revierte manuales.
- `ReconciliarStockService` — recalcula `stock_actual` desde el ledger (mantenimiento).

**Autorizaciones**
- `SolicitarAutorizacionService` — crea `Autorizacion` pendiente. Emite `AutorizacionSolicitada`.
- `ResolverAutorizacionService` — admin aprueba/rechaza; en aprobación invoca el servicio destino (cancelar ítem, anular orden, entrada/ajuste) y enlaza el resultado. Emite `AutorizacionResuelta`.

**Impresión**
- `GenerarComandaService` / `GenerarTicketService` — construyen `contenido_json` con datos de `ConfiguracionEstablecimiento`; despachan el Job de impresión; **si no hay impresora, generan PDF** (P15).

---

## 12. EVENTOS DE DOMINIO

| Evento | Emitido por | Lo consumen |
|---|---|---|
| EstablecimientoCreado | CrearEstablecimientoService | Auditoría |
| UsuarioCreado / UsuarioModificado | servicios de Usuario | Auditoría |
| CajaAbierta / CajaCerrada | servicios de Caja | Auditoría |
| OrdenCreada | CrearOrdenService | Auditoría |
| ItemAgregado | AgregarItemService | Auditoría |
| ItemConfirmado | ConfirmarComandaService | **EnviarComanda** (impresión), Auditoría |
| ItemCancelado | CancelarItemService | RevertirInventario (solo si hubo venta), Auditoría |
| OrdenAnulada | AnularOrdenService | LiberarMesa, RevertirInventario (solo si hubo venta), Auditoría |
| PagoRegistrado | RegistrarPagoService | Auditoría |
| **OrdenPagada** | RegistrarPagoService | **DescontarInventario** (P1), **ImprimirTicket**, **LiberarMesa**, Auditoría |
| MovimientoRegistrado | RegistrarMovimientoService | Auditoría |
| StockBajoDetectado | servicios de Inventario | NotificarStockBajo |
| AutorizacionSolicitada / Resuelta | servicios de Autorización | (notificar admin), Auditoría |
| TicketReimpreso | GenerarTicketService | Auditoría |

---

## 13. LISTENERS

| Listener | Escucha | Acción | Modo |
|---|---|---|---|
| RegistrarAuditoria | casi todos | escribe en `auditoria` | **síncrono** (misma transacción) |
| DescontarInventario | **OrdenPagada** | `DescontarInventarioService` | síncrono |
| RevertirInventario | ItemCancelado, OrdenAnulada | `RevertirMovimientoService` (solo movimientos `venta`) | síncrono |
| LiberarMesa | OrdenPagada, OrdenAnulada | estado derivado: ya no hay orden abierta | síncrono |
| ImprimirTicket | OrdenPagada | despacha `ImprimirTicketJob` (PDF si no hay impresora) | **encolado** |
| EnviarComanda | ItemConfirmado | despacha `EnviarComandaJob` | encolado |
| NotificarStockBajo | StockBajoDetectado | alerta | encolado |

> **Consecuencia clave de P1 (descuento al cobrar).** Como el inventario se mueve **solo al cobrar**, y tanto la cancelación de ítems como la anulación de orden ocurren mientras la orden está **abierta** (antes del cobro), en el flujo normal **no hay stock que revertir**: cancelar o anular una orden no cobrada es neutral para el inventario. `RevertirInventario` queda como salvaguarda y prácticamente no se dispara en V1. Esto **simplifica** el sistema y elimina una clase entera de inconsistencias; es una de las ventajas de haber elegido descuento al cobro.

---

## 14. JOBS (COLA)

| Job | Disparador | Cola | Reintentos |
|---|---|---|---|
| ImprimirTicketJob | OrdenPagada / reimpresión | `impresion` | sí (backoff); si no hay impresora → genera PDF (P15) |
| EnviarComandaJob | ItemConfirmado | `impresion` | sí; PDF si no hay impresora |
| GenerarReporteExportJob | exportación PDF/Excel | `reportes` | no (idempotente por solicitud) |
| ReconciliarStockJob | programado / manual | `mantenimiento` | sí |

La impresión nunca bloquea el cobro (se encola). Si la impresora falla, el ticket queda registrado y reimprimible (sin autorización, P14).

---

## 15. ESTRATEGIA DE AUDITORÍA

Tabla `auditoria` append-only. Población **híbrida**:

1. **CRUD sensible (Observer + trait `Auditable`).** Modelos marcados registran `created/updated/deleted` con `datos_antes`/`datos_despues` (diff) en JSONB: usuarios, productos, insumos, mesas, configuración, etc.
2. **Acciones de negocio (eventos → `RegistrarAuditoria`).** Abrir/cerrar caja, crear/cobrar/anular orden, cancelar ítem, resolver autorización, mermas/ajustes, **impersonación de super_admin (P17)**, **reimpresión (P14)** — con `accion` semántica (`caja.cerrada`, `orden.anulada`, `soporte.impersonacion`, `ticket.reimpreso`).

Cada registro: `id_establecimiento` (nullable para super_admin), `usuario_id`, `accion`, `entidad`, `entidad_id`, `datos_antes`, `datos_despues`, `ip`, `created_at`.

**Consistencia:** la auditoría de acciones financieras se escribe **dentro de la misma transacción** que la acción; si esta se revierte, su auditoría también.

**Consulta:** ADMIN ve su tenant (vía scope); SUPER_ADMIN ve todo (incluido tenant nulo). Filtros por fecha, usuario, acción, entidad.

---

## 16. ESTRATEGIA DE REPORTES

**Lectura separada de escritura:** Query Services de solo lectura (`Domain/Reportes/Queries`) con consultas agregadas filtradas por `id_establecimiento` y rango de fecha en la zona horaria del establecimiento.

| Reporte | Fuente | Notas |
|---|---|---|
| Dashboard del día | ordenes, detalle_orden, pagos | ventas, órdenes abiertas/cerradas, top productos |
| Ventas (diario/semanal/mensual/anual) | ordenes pagadas, pagos | agrupado por periodo |
| Inventario: stock / stock bajo | insumos | índice parcial `stock_actual <= stock_minimo` |
| Inventario: movimientos / mermas | movimientos_inventario | por tipo |
| Caja | sesiones_caja | aperturas, cierres, **diferencias + motivo** (P5) |
| Medios de pago | pagos | **efectivo arqueado; tarjeta/transferencia solo reporte** (P6) |
| Cancelaciones | detalle_orden(cancelado) + autorizaciones | usuario, motivo, fecha |
| Utilidad/Margen (P20, **pendiente**) | detalle_orden + costo | por defecto `costo_referencia`; insumos si controla inventario. **Diseñado como configurable** para ajustar cuando el equipo confirme |

**Alcance por rol (P21):** el OPERADOR ve solo lo correspondiente a **su turno/sesión de caja**; el ADMIN, todo el establecimiento. Aplicado vía `ReportePolicy` + filtro por `sesion_caja` del operador.

**Exportación:** `GenerarReporteExportJob` produce PDF (dompdf) y Excel (Laravel Excel) en cola.

---

## 17. VALIDACIÓN (FORM REQUESTS)

Cada endpoint de escritura tiene su Form Request con las reglas de la Fase 4 (obligatoriedad, formato, unicidad por tenant, no-negatividad). Unicidad usa el `id_establecimiento` del `TenantContext`. Reglas nuevas de Fase 9: **motivo obligatorio al cerrar caja con diferencia** (P5), **tasa de impuesto válida en configuración** (P11). La validación de *forma* va en el Form Request; la de *estado de negocio* (caja abierta, mesa ocupada, saldo) va en el servicio y lanza excepciones de dominio.

---

## 18. SERIALIZACIÓN (API RESOURCES)

API Resources por entidad bajo `Api/V1`, para no exponer columnas internas y versionar el contrato; colecciones paginadas.

---

## 19. TRANSACCIONES, CONCURRENCIA E INTEGRIDAD

- **Transacciones** en todo servicio de escritura; auditoría financiera incluida.
- **Bloqueos pesimistas** en apertura de caja, creación de orden sobre mesa y cierre de caja.
- **Respaldo declarativo (DER):** índices únicos parciales (1 caja abierta, 1 orden abierta por mesa) y unicidades por tenant.
- **Idempotencia de pagos:** clave de idempotencia por intento de cobro para evitar doble registro ante reintentos.
- **Stock como ledger:** `stock_actual` se actualiza siempre junto al movimiento, dentro de la transacción.

---

## 20. MANEJO DE ERRORES (EXCEPCIONES DE DOMINIO)

| Excepción | HTTP | Mensaje |
|---|---|---|
| CajaCerradaException | 409 | "Abre la caja para poder vender." |
| CajaYaAbiertaException | 409 | "Ya hay una caja abierta." |
| OrdenesAbiertasException | 409 | "No puedes cerrar la caja con órdenes abiertas." |
| MotivoDiferenciaRequeridoException | 422 | "Indica el motivo de la diferencia." (P5) |
| MesaOcupadaException | 409 | "La mesa ya tiene una orden abierta." |
| OrdenNoModificableException | 409 | "La orden ya fue pagada." |
| PagoInsuficienteException | 422 | "El pago no cubre el total." |
| RequiereAutorizacionException | 403 | "Esta acción requiere autorización del administrador." |
| AutorizacionResueltaException | 409 | "La solicitud ya fue resuelta." |
| EstablecimientoInactivoException | 423 | "El establecimiento está desactivado." (P19) |

Las de forma las gestiona `ValidationException` (422) con los mensajes del Form Request.

---

## 21. SEEDERS Y MIGRACIONES

- **Migración:** orden del anexo del DER + tablas Spatie (con `teams`) + **los ajustes §2.1** (`unidades_medida.id_establecimiento` nullable; `configuracion_establecimiento.aplica_impuesto` y `tasa_impuesto`).
- **Seeders:**
  - `RolesPermisosSeeder` — permisos del §8 + roles `super_admin`, `admin`, `operador`.
  - `CatalogosGlobalesSeeder` — `tipos_orden` (mesa/barra/llevar), `tipos_pago` (efectivo/tarjeta/transferencia), `unidades_medida` predefinidas (tenant nulo, P4).
  - `SuperAdminSeeder` — super_admin inicial (tenant nulo).

---

## 22. ESTRATEGIA DE PRUEBAS

- **Unitarias** de servicios: arqueo (efectivo, diferencia + motivo), totalizador (impuesto sumado aparte), descuento de inventario al cobrar, pago dividido por monto, folio continuo.
- **Feature** con Sanctum y matriz de permisos (cada rol × cada acción, incluidos los 🔐).
- **Aislamiento multi-tenant:** un usuario de A nunca obtiene datos de B; unidades de medida muestran globales + propias.
- **Concurrencia:** dobles aperturas de caja / dobles órdenes en una mesa fallan por índice único.

---

## 23. RUTA PARA INICIAR DESARROLLO

1. Migraciones del DER + ajustes §2.1 + tablas Spatie (teams) → seeders.
2. Infraestructura tenant: `TenantContext`, `BelongsToTenant`, `TenantScope`, `IncluyeGlobales`, middleware.
3. Modelos + relaciones + casts + traits.
4. Sanctum + Policies + `Gate::before` super_admin.
5. Caja (abrir/cerrar con arqueo efectivo + motivo de diferencia).
6. Órdenes + Pagos + Totalizador (impuesto configurable) + descuentos del cajero + eventos.
7. Inventario: descuento **al cobrar**, recetas, salvaguarda de reversa.
8. Autorizaciones (flujo de dos niveles) → cancelaciones/ajustes.
9. Auditoría (Observer + listeners, incluida impersonación y reimpresión).
10. Impresión (jobs + fallback PDF) y Reportes (alcance por rol + exportadores).
11. Pruebas (permisos, aislamiento, concurrencia, arqueo).

**P16, P20 y los ajustes al DER de §2.1 ya están resueltos e incorporados en el DER V1.2.** Antes de congelar el contrato de API solo resta confirmar la reconciliación roles/Spatie de §8 y verificar que la implementación respete las decisiones ratificadas.
