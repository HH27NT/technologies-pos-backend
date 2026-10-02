# Database Dictionary — Diccionario de Datos Oficial

**Proyecto:** SaaS POS Multi-Tenant (bares, cervecerías, cantinas, cafeterías, restaurantes pequeños)
**Modelo:** DER V1.4 (25 tablas de dominio + infraestructura Spatie/Sanctum) · **Motor:** PostgreSQL 15+
**Propósito:** definición campo por campo del esquema como fuente de verdad para desarrollo y generación de código. Documenta únicamente lo existente en el DER.

> **Cambios V1.4 (2026-08-15) — modo terminal compartida:** `ordenes.id_mesero` y `pagos.id_mesero` (nullable), `configuracion_establecimiento.terminal_compartida` + `bloqueo_terminal_segundos`, y tabla nueva `mesero_pins`. **Regla de resolución obligatoria:** de quién es una venta se responde con `COALESCE(id_mesero, id_usuario)` (`App\Domain\Ordenes\MeseroEfectivo`), nunca leyendo `id_usuario` a pelo — en el modo compartido eso devuelve la cuenta del dispositivo y el número sale mal **sin fallar**.

> **Cambios V1.3 (sincronizado con las migraciones al 2026-08-14):** `sesiones_caja` gana `motivo`; `autorizaciones` gana `datos` (JSONB) y `metodo` (`asincrono`/`override`); `pagos` gana el índice único parcial de idempotencia; `roles` gana `etiqueta` y `descripcion`; tablas nuevas `autorizacion_pins` y `autorizacion_intentos`. **Corrección:** la sección `roles` describía campos `nombre`/`slug` de un catálogo global que nunca existió — la tabla real es la de Spatie con _teams_ (`name`/`guard_name` + `id_establecimiento`).

> **Cambios V1.2 (ratificados Fase 9):** `unidades_medida` ahora es híbrido (gana `id_establecimiento` nullable); `configuracion_establecimiento` gana `aplica_impuesto` y `tasa_impuesto`; `pagos.propina` queda reservado para V2; `detalle_orden` gana `enviado` (BOOLEAN default `false`) para el control de ítems enviados a comanda.

> Notación de tipos: `BIGINT` (PK/FK), `VARCHAR(n)`, `TEXT`, `DECIMAL(p,s)`, `BOOLEAN`, `TIMESTAMP`, `JSONB`, `ENUM(...)`. Los ejemplos de valores son ilustrativos.

---

# establecimientos

## Descripción
Negocio cliente del SaaS y raíz del aislamiento multi-tenant.

## Campos

**id** · BIGINT · Nullable: No · Identificador único del establecimiento. · Ej: `1`, `2`. · Regla: PK autoincremental.
**nombre** · VARCHAR(150) · No · Nombre del establecimiento. · Ej: `Bar La Cantina`. · —
**razon_social** · VARCHAR(150) · Sí · Razón social fiscal. · Ej: `Cantina SA de CV`. · —
**rfc** · VARCHAR(13) · Sí · Registro fiscal. · Ej: `CAN980101AB1`. · Formato fiscal válido si se captura.
**direccion** · TEXT · Sí · Dirección física. · Ej: `Av. Reforma 100`. · —
**telefono** · VARCHAR(20) · Sí · Teléfono de contacto. · Ej: `5512345678`. · —
**email** · VARCHAR(150) · Sí · Correo de contacto. · Ej: `hola@cantina.mx`. · Formato de correo válido.
**logo_url** · VARCHAR(255) · Sí · URL del logo. · Ej: `https://.../logo.png`. · —
**zona_horaria** · VARCHAR(50) · Sí · Zona horaria del negocio. · Ej: `America/Mexico_City`. · Base para rangos de fecha en reportes.
**moneda** · VARCHAR(3) · Sí · Código de moneda. · Ej: `MXN`, `USD`. · —
**activo** · BOOLEAN · No · Indica si el establecimiento está operativo. · Ej: `true`. · Si es `false`, sus usuarios no inician sesión y sus órdenes quedan bloqueadas.
**created_at** · TIMESTAMP · No · Fecha de alta. · Ej: `2026-01-10 12:00`. · —
**updated_at** · TIMESTAMP · No · Última modificación. · — · —
**deleted_at** · TIMESTAMP · Sí · Marca de borrado lógico. · Ej: `null`. · Soft delete: no se elimina físicamente.

---

# configuracion_establecimiento

## Descripción
Parámetros operativos y de impresión de un establecimiento (relación 1:1).

## Campos

**id** · BIGINT · No · Identificador de la configuración. · Ej: `1`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento dueño de la configuración. · Ej: `1`. · FK → establecimientos.id; UNIQUE (1:1).
**nombre_comercial** · VARCHAR(150) · Sí · Nombre comercial a mostrar/imprimir. · Ej: `La Cantina`. · Puede diferir de la razón social.
**telefono_ticket** · VARCHAR(20) · Sí · Teléfono impreso en el ticket. · Ej: `5512345678`. · Puede diferir del teléfono de contacto.
**direccion_ticket** · TEXT · Sí · Dirección impresa en el ticket. · Ej: `Av. Reforma 100`. · —
**impresion_automatica** · BOOLEAN · No · Si los tickets se imprimen automáticamente. · Ej: `true`. · Controla la impresión automática al cobrar.
**terminal_compartida** · BOOLEAN · No · Varios meseros comparten una tablet. · Ej: `false`. · Default `false`. Apagado, el POS no pide PIN y `id_mesero` nunca se llena: el establecimiento con dispositivo por mesero no configura nada.
**bloqueo_terminal_segundos** · SMALLINT · No · Auto-bloqueo de la tablet por inactividad. · Ej: `120`. · Default 120 s. Una terminal desatendida en la barra es el riesgo que introduce el modo compartido.
**stock_minimo_global** · DECIMAL(12,3) · Sí · Stock mínimo por defecto para insumos. · Ej: `5.000`. · Valor de referencia para alertas.
**aplica_impuesto** · BOOLEAN · No · Si el establecimiento aplica impuesto a las ventas. · Ej: `true`. · Si es `false`, el total no suma impuesto.
**tasa_impuesto** · DECIMAL(5,2) · Sí · Tasa de impuesto a aplicar (porcentaje). · Ej: `16.00`. · Configurable por establecimiento; el precio_venta no la incluye, se suma aparte.
**created_at** · TIMESTAMP · No · Fecha de alta. · — · —
**updated_at** · TIMESTAMP · No · Última modificación. · — · —

---

# roles

## Descripción
Roles **por establecimiento** (paquete de permisos). Es la tabla de **Spatie Permission con _teams_**: `id_establecimiento` es el `team_foreign_key`. No es un catálogo global.

## Campos

**id** · BIGINT · No · Identificador del rol. · Ej: `1`. · PK.
**id_establecimiento** · BIGINT · Sí · Establecimiento dueño del rol (team de Spatie). · Ej: `9` o `null`. · **NULL = rol de plataforma** (`super_admin`). Índice.
**name** · VARCHAR · No · Identificador **técnico** de Spatie. · Ej: `mesero`. · UNIQUE(id_establecimiento, name, guard_name). Estable, sin espacios; lo consultan `hasRole()` y `model_has_roles`. **Nunca se muestra en la UI.**
**guard_name** · VARCHAR · No · Guard de autenticación. · Ej: `web`. · Parte del UNIQUE.
**etiqueta** · VARCHAR(60) · Sí · Nombre legible y renombrable de un rol a medida. · Ej: `Cajero nocturno`. · NULL en los roles del sistema (se etiquetan desde `CatalogoRoles`). Renombrar no rompe asignaciones porque `name` no cambia.
**descripcion** · VARCHAR(200) · Sí · Para qué sirve el rol; se muestra en el editor. · Ej: `Cobra en el turno de noche`. · —
**created_at** · TIMESTAMP · No · Fecha de alta. · — · —
**updated_at** · TIMESTAMP · No · Última modificación. · — · —

Roles del sistema (materializados por tenant desde `App\Domain\Usuarios\CatalogoRoles`): `super_admin`, `admin`, `gerente`, `operador`, `mesero`. Son **inmutables**: el editor los clona, no los edita, porque `roles:sincronizar` (que corre en cada arranque) pisaría cualquier cambio.

---

# permissions · role_has_permissions · model_has_roles · model_has_permissions

## Descripción
Infraestructura de Spatie Permission. No estaban documentadas en V1.2.

## Campos

**permissions** · `id`, `name` (ej. `ordenes.cobrar`), `guard_name`, timestamps · UNIQUE(name, guard_name). **Global**: el catálogo de permisos es el mismo para todos los tenants.
**role_has_permissions** · `permission_id`, `role_id` · PK compuesta. Define qué permisos tiene cada rol.
**model_has_roles** · `role_id`, `model_type`, `model_id`, `id_establecimiento` · PK compuesta **con el tenant**: la misma persona puede tener rol distinto en establecimientos distintos. **Es la verdad de la asignación** (`usuarios.id_rol` es solo cache).
**model_has_permissions** · misma forma, para permisos directos sin rol intermedio. **Sin uso en V1**: todo permiso llega por rol.
**personal_access_tokens** · tokens de Sanctum (autenticación Bearer). Forma estándar del paquete.

---

# usuarios

## Descripción
Personas que acceden al sistema: super administradores y personal de cada establecimiento.

## Campos

**id** · BIGINT · No · Identificador del usuario. · Ej: `10`. · PK.
**id_establecimiento** · BIGINT · Sí · Establecimiento al que pertenece. · Ej: `1` o `null`. · FK → establecimientos.id. **NULL = super_admin** (no pertenece a un establecimiento).
**id_rol** · BIGINT · Sí · Rol principal (**cache**). · Ej: `2`. · FK → roles.id. ⚠️ **No es la verdad**: la asignación real vive en `model_has_roles`. Es un invariante que ambos coincidan — quien asigne rol debe escribir en los dos lados.
**nombre** · VARCHAR(100) · No · Nombre del usuario. · Ej: `Ana Pérez`. · —
**email** · VARCHAR(150) · Sí · Correo / login. · Ej: `ana@cantina.mx`. · UNIQUE(id_establecimiento, email).
**username** · VARCHAR(50) · Sí · Nombre de usuario / login. · Ej: `ana`. · UNIQUE(id_establecimiento, username).
**password_hash** · VARCHAR(255) · No · Hash de la contraseña. · Ej: `$2y$...`. · Nunca se almacena en texto plano.
**activo** · BOOLEAN · No · Si el usuario puede iniciar sesión. · Ej: `true`. · Usuario inactivo no autentica.
**remember_token** · VARCHAR(100) · Sí · Token de sesión recordada. · — · Uso estándar del framework.
**email_verified_at** · TIMESTAMP · Sí · Fecha de verificación de correo. · — · —
**created_at** · TIMESTAMP · No · Fecha de alta. · — · —
**updated_at** · TIMESTAMP · No · Última modificación. · — · —
**deleted_at** · TIMESTAMP · Sí · Borrado lógico. · — · Soft delete; debe existir al menos un admin activo por establecimiento.

---

# sesiones_caja

## Descripción
Periodo entre apertura y cierre de caja en un establecimiento.

## Campos

**id** · BIGINT · No · Identificador de la sesión de caja. · Ej: `100`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento de la caja. · Ej: `1`. · FK → establecimientos.id.
**id_usuario_apertura** · BIGINT · No · Usuario que abrió la caja. · Ej: `10`. · FK → usuarios.id.
**id_usuario_cierre** · BIGINT · Sí · Usuario que cerró la caja. · Ej: `null` mientras está abierta. · FK → usuarios.id.
**monto_inicial** · DECIMAL(12,2) · No · Fondo con el que se abre la caja. · Ej: `500.00`. · ≥ 0.
**monto_sistema** · DECIMAL(12,2) · Sí · Efectivo esperado calculado al cierre. · Ej: `3200.00`. · Calculado a partir del efectivo registrado.
**monto_contado** · DECIMAL(12,2) · Sí · Efectivo contado físicamente (arqueo). · Ej: `3180.00`. · Capturado al cerrar.
**diferencia** · DECIMAL(12,2) · Sí · monto_contado − monto_sistema. · Ej: `-20.00`. · Si ≠ 0, requiere motivo (regla de negocio).
**motivo** · VARCHAR(500) · Sí · Justificación del cierre con diferencia. · Ej: `Faltante por cambio mal dado`. · Columna portable (sin índices) para paridad SQLite ↔ PostgreSQL.
**estado** · ENUM · No · Estado de la sesión. · Valores: `abierta`, `cerrada`. · Solo una `abierta` por establecimiento; una cerrada no se reabre.
**abierta_at** · TIMESTAMP · No · Momento de apertura. · — · —
**cerrada_at** · TIMESTAMP · Sí · Momento de cierre. · — · Nulo mientras está abierta.

Regla: no se puede vender sin caja abierta; no se cierra con órdenes abiertas.

---

# mesas

## Descripción
Posiciones de consumo de un establecimiento.

## Campos

**id** · BIGINT · No · Identificador de la mesa. · Ej: `7`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento dueño. · Ej: `1`. · FK → establecimientos.id.
**numero** · INT · No · Número de mesa. · Ej: `5`. · UNIQUE(id_establecimiento, numero).
**nombre** · VARCHAR(50) · Sí · Etiqueta opcional. · Ej: `Terraza 1`. · —
**zona** · VARCHAR(50) · Sí · Zona/área. · Ej: `Terraza`. · —
**capacidad** · INT · Sí · Número de comensales. · Ej: `4`. · > 0.
**activa** · BOOLEAN · No · Si la mesa está habilitada. · Ej: `true`. · No se desactiva con orden abierta.
**deleted_at** · TIMESTAMP · Sí · Borrado lógico. · — · Soft delete.

Regla: el estado ocupada/libre se deriva de la existencia de una orden abierta; se libera al pagar.

---

# tipos_orden (GLOBAL)

## Descripción
Catálogo global del tipo de orden.

## Campos

**id** · BIGINT · No · Identificador del tipo. · Ej: `1`. · PK.
**nombre** · VARCHAR(50) · No · Nombre del tipo. · Valores: `mesa`, `barra`, `llevar`. · —
**activo** · BOOLEAN · No · Si el tipo está habilitado. · Ej: `true`. · Catálogo compartido por todos los establecimientos.

---

# ordenes

## Descripción
Cuenta de consumo de un cliente, de tipo mesa o barra; núcleo transaccional de la venta.

## Campos

**id** · BIGINT · No · Identificador de la orden. · Ej: `5001`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento. · Ej: `1`. · FK → establecimientos.id.
**id_sesion_caja** · BIGINT · No · Sesión de caja en la que se generó. · Ej: `100`. · FK → sesiones_caja.id.
**id_mesa** · BIGINT · Sí · Mesa asociada. · Ej: `7` o `null`. · FK → mesas.id; NULL en barra/llevar.
**id_tipo_orden** · BIGINT · No · Tipo de orden. · Ej: `1`. · FK → tipos_orden.id.
**id_usuario** · BIGINT · No · **Cuenta** que abrió la orden. · Ej: `10`. · FK → usuarios.id.
**id_mesero** · BIGINT · Sí · **Persona** que atendió, cuando la cuenta no la identifica. · Ej: `14` o `null`. · FK → usuarios.id. Solo se llena en terminal compartida, al firmar con PIN. De quién es la venta: `COALESCE(id_mesero, id_usuario)`.
**folio** · VARCHAR(20) · No · Folio de la orden. · Ej: `A-000123`. · UNIQUE(id_establecimiento, folio); secuencia continua por establecimiento.
**estado** · ENUM · No · Estado de la orden. · Valores: `abierta`, `pagada`, `anulada`. · Una orden pagada no se reabre; pasar a `anulada` requiere autorización.
**descuento** · DECIMAL(12,2) · Sí · Descuento a nivel orden. · Ej: `0.00`. · ≥ 0.
**subtotal** · DECIMAL(12,2) · No · Suma de ítems antes de impuesto. · Ej: `250.00`. · ≥ 0.
**impuesto** · DECIMAL(12,2) · Sí · Impuesto calculado. · Ej: `40.00`. · ≥ 0.
**total** · DECIMAL(12,2) · No · Total a cobrar. · Ej: `290.00`. · ≥ 0; importes "congelados" en la orden.
**notas** · TEXT · Sí · Observaciones. · Ej: `Sin hielo`. · —
**abierta_at** · TIMESTAMP · No · Momento de apertura. · — · —
**cerrada_at** · TIMESTAMP · Sí · Momento de cierre (pago o anulación). · — · —

---

# detalle_orden

## Descripción
Renglón de una orden: un producto con su cantidad, precio y estado.

## Campos

**id** · BIGINT · No · Identificador del renglón. · Ej: `9001`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento. · Ej: `1`. · FK → establecimientos.id.
**id_orden** · BIGINT · No · Orden a la que pertenece. · Ej: `5001`. · FK → ordenes.id; ON DELETE CASCADE.
**id_producto** · BIGINT · No · Producto vendido. · Ej: `300`. · FK → productos.id.
**id_autorizacion** · BIGINT · Sí · Autorización que respalda su cancelación. · Ej: `null`. · FK → autorizaciones.id.
**cantidad** · DECIMAL(10,3) · No · Cantidad vendida. · Ej: `2.000`. · > 0.
**precio_unitario** · DECIMAL(12,2) · No · Precio unitario al momento de la venta. · Ej: `45.00`. · Se "congela" el precio del producto.
**descuento_item** · DECIMAL(12,2) · Sí · Descuento del renglón. · Ej: `0.00`. · ≥ 0.
**subtotal** · DECIMAL(12,2) · No · cantidad × precio − descuento. · Ej: `90.00`. · ≥ 0.
**enviado** · BOOLEAN · No · Si el renglón ya fue enviado a comanda. · Ej: `false`. · Default `false`; la cantidad solo se modifica mientras es `false`; pasa a `true` al confirmar la comanda.
**estado_item** · ENUM · No · Estado del renglón. · Valores: `activo`, `cancelado`. · La cancelación requiere autorización.
**cancelado_at** · TIMESTAMP · Sí · Momento de cancelación. · Ej: `null`. · Se llena al cancelar.
**notas** · VARCHAR(255) · Sí · Observaciones del renglón. · Ej: `Bien frío`. · —

---

# tipos_pago (GLOBAL)

## Descripción
Catálogo global de medios de pago.

## Campos

**id** · BIGINT · No · Identificador del tipo de pago. · Ej: `1`. · PK.
**nombre** · VARCHAR(50) · No · Nombre del medio. · Valores: `efectivo`, `tarjeta`, `transferencia`. · —
**activo** · BOOLEAN · No · Si el medio está habilitado. · Ej: `true`. · Catálogo compartido por todos los establecimientos.

---

# pagos

## Descripción
Registro de un cobro aplicado a una orden (soporta pago dividido).

## Campos

**id** · BIGINT · No · Identificador del pago. · Ej: `7001`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento. · Ej: `1`. · FK → establecimientos.id.
**id_orden** · BIGINT · No · Orden cobrada. · Ej: `5001`. · FK → ordenes.id.
**id_tipo_pago** · BIGINT · No · Medio de pago usado. · Ej: `1`. · FK → tipos_pago.id.
**id_usuario** · BIGINT · No · **Cuenta** que registró el cobro. · Ej: `10`. · FK → usuarios.id.
**id_mesero** · BIGINT · Sí · **Persona** que firmó el cobro con su PIN. · Ej: `14` o `null`. · FK → usuarios.id. Va aparte del de la orden porque quien abre la mesa no siempre es quien cobra; el reporte "cuánto entró por cada quien" se calcula sobre los pagos.
**monto** · DECIMAL(12,2) · No · Monto del pago. · Ej: `290.00`. · > 0; la suma de pagos debe cubrir el total.
**propina** · DECIMAL(12,2) · Sí · Propina asociada al pago. · Ej: `0.00`. · **Reservado para V2; sin uso en V1** (la propina no se registra en el sistema; ver Observaciones del Modelo).
**referencia** · VARCHAR(100) · Sí · Referencia del pago (autorización tarjeta, folio transferencia). · Ej: `AUTH-558210`. · **Idempotencia:** índice único parcial `uq_pagos_orden_referencia_parcial` sobre (id_orden, referencia) WHERE referencia IS NOT NULL — impide duplicar el cobro ante un reintento. Los pagos sin referencia (efectivo) no se restringen. Solo PostgreSQL; en SQLite lo cubre `RegistrarPagoService` bajo bloqueo.
**pagado_at** · TIMESTAMP · No · Momento del pago. · — · —

---

# tickets

## Descripción
Documento asociado a una orden: comanda o ticket de cobro.

## Campos

**id** · BIGINT · No · Identificador del ticket. · Ej: `8001`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento. · Ej: `1`. · FK → establecimientos.id.
**id_orden** · BIGINT · No · Orden asociada. · Ej: `5001`. · FK → ordenes.id.
**id_usuario** · BIGINT · No · Usuario que generó el ticket. · Ej: `10`. · FK → usuarios.id.
**id_impresora** · BIGINT · Sí · Impresora destino. · Ej: `null`. · FK → impresoras.id; si es NULL, se exporta a PDF.
**folio_ticket** · VARCHAR(20) · Sí · Folio del ticket. · Ej: `T-000456`. · —
**contenido_json** · JSONB · No · Contenido renderizable del documento. · Ej: `{"items":[...]}`. · Estructura del ticket/comanda.
**tipo** · ENUM · No · Tipo de documento. · Valores: `comanda`, `cobro`. · La comanda va a barra/cocina; el cobro al cliente.
**impreso_at** · TIMESTAMP · Sí · Momento de impresión. · — · —

---

# impresoras

## Descripción
Impresora física configurada en un establecimiento.

## Campos

**id** · BIGINT · No · Identificador de la impresora. · Ej: `20`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento dueño. · Ej: `1`. · FK → establecimientos.id.
**nombre** · VARCHAR(50) · No · Nombre de la impresora. · Ej: `Caja 1`. · —
**tipo** · ENUM · No · Función de la impresora. · Valores: `ticket`, `barra`, `cocina`, `admin`. · —
**conexion** · VARCHAR(150) · Sí · Dirección/cola de conexión. · Ej: `192.168.1.50`. · —
**activa** · BOOLEAN · No · Si está habilitada. · Ej: `true`. · —
**deleted_at** · TIMESTAMP · Sí · Borrado lógico. · — · Soft delete.

---

# categorias_producto

## Descripción
Agrupación de productos para la venta.

## Campos

**id** · BIGINT · No · Identificador de la categoría. · Ej: `40`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento dueño. · Ej: `1`. · FK → establecimientos.id.
**nombre** · VARCHAR(80) · No · Nombre de la categoría. · Ej: `Cervezas`. · —
**orden_display** · INT · Sí · Orden de despliegue en la venta. · Ej: `1`. · Define el orden visual.
**activo** · BOOLEAN · No · Si la categoría está habilitada. · Ej: `true`. · Inactiva no se muestra en venta.
**deleted_at** · TIMESTAMP · Sí · Borrado lógico. · — · Soft delete.

---

# productos

## Descripción
Ítem vendible del catálogo.

## Campos

**id** · BIGINT · No · Identificador del producto. · Ej: `300`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento dueño. · Ej: `1`. · FK → establecimientos.id.
**id_categoria** · BIGINT · No · Categoría del producto. · Ej: `40`. · FK → categorias_producto.id.
**nombre** · VARCHAR(120) · No · Nombre del producto. · Ej: `Cerveza Clara 355ml`. · —
**descripcion** · TEXT · Sí · Descripción. · Ej: `Botella 355ml`. · —
**precio_venta** · DECIMAL(12,2) · No · Precio de venta. · Ej: `45.00`. · ≥ 0.
**costo_referencia** · DECIMAL(12,2) · Sí · Costo de referencia para reportes de margen. · Ej: `22.00`. · No es para inventario; para utilidad/margen.
**controla_inventario** · BOOLEAN · No · Si la venta descuenta inventario vía receta. · Ej: `false`. · `true` = descuenta insumos de su receta; `false` = no afecta inventario.
**disponible** · BOOLEAN · No · Si está disponible para venta. · Ej: `true`. · Distinto de borrado lógico: oculta el producto de la venta sin eliminarlo.
**sku** · VARCHAR(50) · Sí · Código del producto. · Ej: `CERV-CLR-355`. · —
**deleted_at** · TIMESTAMP · Sí · Borrado lógico. · — · Soft delete.

---

# unidades_medida (GLOBAL + PROPIAS)

## Descripción
Catálogo híbrido de unidades de medida de insumos: unidades predefinidas compartidas más unidades propias de cada establecimiento.

## Campos

**id** · BIGINT · No · Identificador de la unidad. · Ej: `1`. · PK.
**id_establecimiento** · BIGINT · Sí · Establecimiento dueño de la unidad. · Ej: `null` o `1`. · FK → establecimientos.id. **NULL = unidad predefinida global** (semilla, solo lectura); no nula = unidad propia del establecimiento.
**nombre** · VARCHAR(50) · No · Nombre de la unidad. · Ej: `Mililitro`, `Pieza`. · —
**abreviacion** · VARCHAR(10) · Sí · Abreviatura. · Ej: `ml`, `pza`. · —

---

# proveedores

## Descripción
Proveedor de insumos de un establecimiento.

## Campos

**id** · BIGINT · No · Identificador del proveedor. · Ej: `60`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento dueño. · Ej: `1`. · FK → establecimientos.id.
**nombre** · VARCHAR(120) · No · Nombre del proveedor. · Ej: `Distribuidora X`. · —
**telefono** · VARCHAR(20) · Sí · Teléfono. · Ej: `5599887766`. · —
**email** · VARCHAR(150) · Sí · Correo. · Ej: `ventas@distx.mx`. · —
**activo** · BOOLEAN · No · Si está habilitado. · Ej: `true`. · —
**deleted_at** · TIMESTAMP · Sí · Borrado lógico. · — · Soft delete.

---

# insumos

## Descripción
Artículo de inventario con control de existencias; entidad que se descuenta al vender (vía receta) y en movimientos manuales.

## Campos

**id** · BIGINT · No · Identificador del insumo. · Ej: `500`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento dueño. · Ej: `1`. · FK → establecimientos.id.
**id_unidad_medida** · BIGINT · No · Unidad de medida. · Ej: `1`. · FK → unidades_medida.id.
**id_proveedor** · BIGINT · Sí · Proveedor del insumo. · Ej: `60` o `null`. · FK → proveedores.id.
**nombre** · VARCHAR(120) · No · Nombre del insumo. · Ej: `Vodka`. · —
**stock_actual** · DECIMAL(12,3) · No · Existencia actual (cache). · Ej: `1500.000`. · Valor cache; la fuente de verdad es movimientos_inventario.
**stock_minimo** · DECIMAL(12,3) · Sí · Umbral de alerta de stock bajo. · Ej: `200.000`. · Base del reporte de stock bajo.
**costo_unitario** · DECIMAL(12,2) · Sí · Costo unitario del insumo. · Ej: `0.30`. · —
**activo** · BOOLEAN · No · Si está habilitado. · Ej: `true`. · —
**deleted_at** · TIMESTAMP · Sí · Borrado lógico. · — · Soft delete.

---

# recetas_producto

## Descripción
Línea de receta (BOM): insumo y cantidad que consume un producto al venderse.

## Campos

**id** · BIGINT · No · Identificador de la línea de receta. · Ej: `800`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento dueño. · Ej: `1`. · FK → establecimientos.id.
**id_producto** · BIGINT · No · Producto al que pertenece la receta. · Ej: `301`. · FK → productos.id.
**id_insumo** · BIGINT · No · Insumo consumido. · Ej: `500`. · FK → insumos.id.
**cantidad** · DECIMAL(10,3) · No · Cantidad del insumo por unidad de producto. · Ej: `45.000`. · > 0; UNIQUE(id_producto, id_insumo).

---

# movimientos_inventario

## Descripción
Ledger append-only de todos los movimientos de stock de un insumo; fuente de verdad del inventario.

## Campos

**id** · BIGINT · No · Identificador del movimiento. · Ej: `90001`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento. · Ej: `1`. · FK → establecimientos.id.
**id_insumo** · BIGINT · No · Insumo afectado. · Ej: `500`. · FK → insumos.id.
**id_usuario** · BIGINT · No · Usuario que generó el movimiento. · Ej: `10`. · FK → usuarios.id.
**id_orden** · BIGINT · Sí · Orden que originó el movimiento. · Ej: `5001` o `null`. · FK → ordenes.id; presente en salidas por venta.
**id_autorizacion** · BIGINT · Sí · Autorización que respalda el movimiento. · Ej: `null`. · FK → autorizaciones.id; presente en entradas/ajustes solicitados por operador.
**tipo** · ENUM · No · Tipo de movimiento. · Valores: `entrada`, `salida`, `venta`, `merma`, `rotura`, `ajuste`, `consumo_interno`. · Define si suma o resta stock.
**cantidad** · DECIMAL(12,3) · No · Cantidad movida. · Ej: `90.000`. · > 0.
**costo_unitario** · DECIMAL(12,2) · Sí · Costo unitario del movimiento. · Ej: `0.30`. · —
**stock_resultante** · DECIMAL(12,3) · Sí · Stock tras aplicar el movimiento. · Ej: `1410.000`. · Permite negativos (regla: venta no se bloquea por stock).
**motivo** · TEXT · Sí · Justificación del movimiento. · Ej: `Botella rota`. · Obligatorio en mermas/ajustes (regla de negocio).

---

# autorizaciones

## Descripción
Solicitud de una operación sensible que un administrador debe aprobar.

## Campos

**id** · BIGINT · No · Identificador de la autorización. · Ej: `4000`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento. · Ej: `1`. · FK → establecimientos.id.
**id_usuario_solicita** · BIGINT · No · Usuario que solicita. · Ej: `11`. · FK → usuarios.id.
**id_usuario_autoriza** · BIGINT · Sí · Usuario que resuelve. · Ej: `10` o `null`. · FK → usuarios.id; nulo mientras está pendiente.
**tipo** · ENUM · No · Tipo de operación. · Valores: `cancelar_item`, `anular_orden`, `ajuste_stock`, … · Determina el servicio que se ejecuta al aprobar.
**entidad** · VARCHAR(50) · No · Entidad afectada (referencia polimórfica). · Ej: `detalle_orden`. · No es FK formal.
**entidad_id** · BIGINT · No · Id de la entidad afectada. · Ej: `9001`. · No es FK formal.
**estado** · ENUM · No · Estado de la solicitud. · Valores: `pendiente`, `aprobada`, `rechazada`. · Una solicitud resuelta no cambia de estado.
**motivo** · TEXT · Sí · Motivo de la solicitud. · Ej: `Cliente cambió de opinión`. · Obligatorio al solicitar.
**metodo** · ENUM(`asincrono`, `override`) · No · Origen de la aprobación. · Ej: `override`. · Default `asincrono`. `override` = el autorizador tecleó su PIN y la operación se ejecutó al instante (M14.1).
**datos** · JSONB · Sí · Parámetros de la operación pendiente. · Ej: `{"id_insumo":4,"cantidad":12}`. · Necesario porque una entrada/ajuste de stock lleva datos que **aún no existen como movimiento**: el movimiento se crea al aprobar.
**resuelta_at** · TIMESTAMP · Sí · Momento de resolución. · — · —

---

# auditoria

## Descripción
Bitácora append-only de acciones sensibles del sistema, visible para administradores.

## Campos

**id** · BIGINT · No · Identificador del registro. · Ej: `120000`. · PK.
**id_establecimiento** · BIGINT · Sí · Establecimiento del evento. · Ej: `1` o `null`. · FK → establecimientos.id; NULL en acciones del super_admin.
**id_usuario** · BIGINT · Sí · Usuario responsable. · Ej: `10` o `null`. · FK → usuarios.id.
**accion** · VARCHAR(80) · No · Acción registrada. · Ej: `caja.cerrada`, `orden.anulada`. · —
**entidad** · VARCHAR(50) · No · Entidad afectada (referencia polimórfica). · Ej: `sesiones_caja`. · No es FK formal.
**entidad_id** · BIGINT · Sí · Id de la entidad afectada. · Ej: `100`. · No es FK formal.
**datos_antes** · JSONB · Sí · Estado previo. · Ej: `{"estado":"abierta"}`. · Diff de atributos antes del cambio.
**datos_despues** · JSONB · Sí · Estado posterior. · Ej: `{"estado":"cerrada"}`. · Diff de atributos después del cambio.
**ip** · VARCHAR(45) · Sí · IP de origen. · Ej: `200.10.5.3`. · Soporta IPv4/IPv6.
**created_at** · TIMESTAMP · No · Momento del evento. · — · Registro append-only.

---

# autorizacion_pins

## Descripción
PIN de 6 dígitos que cada autorizador fija para sí mismo. Habilita el **override** de M14.1: el operador teclea el PIN de un autorizador presente y la operación sensible se ejecuta al instante, sin pasar por la bandeja asíncrona.

## Campos

**id** · BIGINT · No · Identificador. · Ej: `3`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento del PIN. · Ej: `9`. · FK → establecimientos.id (cascade).
**id_usuario** · BIGINT · No · Autorizador dueño del PIN. · Ej: `12`. · FK → usuarios.id (cascade).
**pin_hash** · VARCHAR(255) · No · Hash bcrypt del PIN. · Ej: `$2y$...`. · Verificación **real**, timing-safe (`Hash::check`). Salt por fila.
**pin_lookup** · VARCHAR(64) · No · HMAC-SHA256 de `"{id_establecimiento}:{pin}"`. · — · Determinístico: permite resolver la identidad con un WHERE indexado y enforzar unicidad sin exponer el PIN. Usa **`POS_PIN_LOOKUP_KEY`**, clave dedicada, NO la `APP_KEY`.
**actualizado_at** · TIMESTAMP · Sí · Última vez que se fijó el PIN. · — · —
**created_at / updated_at** · TIMESTAMP · No · Alta y modificación. · — · —

Restricciones: UNIQUE(id_usuario, id_establecimiento) — un PIN por persona y tenant. UNIQUE(id_establecimiento, pin_lookup) — el PIN debe ser único dentro del establecimiento, condición para resolver la identidad tecleando solo 6 dígitos.

⚠️ **Operación:** si `POS_PIN_LOOKUP_KEY` rota, todos los lookup quedan inservibles y cada autorizador debe volver a fijar su PIN. Se genera con `php artisan pos:pin-key`. La `APP_KEY` sí puede rotarse sin invalidar PINs.

---

# mesero_pins

## Descripción
PIN de 6 dígitos con el que un mesero se identifica en una terminal compartida, para que la venta se atribuya a la **persona** y no a la cuenta del dispositivo.

## Campos

**id** · BIGINT · No · Identificador. · Ej: `5`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento. · Ej: `9`. · FK → establecimientos.id (cascade).
**id_usuario** · BIGINT · No · Mesero dueño del PIN. · Ej: `14`. · FK → usuarios.id (cascade).
**pin_hash** · VARCHAR(255) · No · Hash bcrypt del PIN. · Ej: `$2y$...`. · Verificación real, timing-safe.
**pin_lookup** · VARCHAR(64) · No · HMAC-SHA256 de `"mesero:{id_establecimiento}:{pin}"`. · — · Prefijo `mesero:` **a propósito**: sin él, quien usara el mismo PIN para autorizar y para firmar produciría el mismo lookup en ambas tablas, revelando que el PIN público de la barra abre también las anulaciones. Usa `POS_PIN_LOOKUP_KEY`.
**actualizado_at** · TIMESTAMP · Sí · Última vez que se fijó. · — · —
**created_at / updated_at** · TIMESTAMP · No · Alta y modificación. · — · —

Restricciones: UNIQUE(id_usuario, id_establecimiento); UNIQUE(id_establecimiento, pin_lookup) — dos meseros con el mismo PIN harían imposible saber a quién atribuir la venta.

⚠️ **Tabla separada de `autorizacion_pins` por seguridad, no por comodidad**: aquel PIN aprueba dinero, este se teclea en público decenas de veces por turno. El flujo de override **rechaza** un PIN de mesero (blindado por test). Este PIN **no autoriza ni autentica**: identificar devuelve un token opaco de vida corta que el POS adjunta al crear la orden y al cobrar — sin él, `id_mesero` viajaría desde el cliente y la atribución sería falsificable.

**Quién lo fija:** el admin (`usuarios.gestionar`), **no** su dueño — al contrario que el PIN de autorización. En una barra con tablet compartida el mesero suele no tener credenciales propias con las que entrar a fijárselo, y exigir self-service dejaría el modo inusable.

---

# autorizacion_intentos

## Descripción
Bitácora de intentos de override **fallidos**. Va en tabla aparte porque `autorizaciones` solo guarda operaciones concedidas, y los rechazos son justo lo que permite detectar tanteo de PINs.

## Campos

**id** · BIGINT · No · Identificador. · Ej: `88`. · PK.
**id_establecimiento** · BIGINT · No · Establecimiento. · Ej: `9`. · FK → establecimientos.id (cascade).
**id_usuario_solicita** · BIGINT · No · Operador que tecleó el PIN. · Ej: `15`. · FK → usuarios.id.
**tipo** · VARCHAR(50) · No · Operación intentada. · Ej: `cancelar_item`. · `cancelar_item` \| `anular_orden` \| `entrada_stock` \| `ajuste_stock`.
**resultado** · VARCHAR(30) · No · Por qué falló. · Ej: `pin_invalido`. · `pin_invalido` (no resuelve o no verifica) \| `sin_permiso` (resuelve a alguien que no puede autorizar eso).
**ip** · VARCHAR(45) · Sí · IP de origen. · Ej: `200.10.5.3`. · —
**terminal** · VARCHAR(50) · Sí · Terminal desde donde se intentó. · Ej: `caja-1`. · —
**datos** · JSONB · Sí · Referencias de la operación intentada. · Ej: `{"id_orden":40}`. · —
**created_at / updated_at** · TIMESTAMP · No · Momento del intento. · — · Índice (id_establecimiento, created_at).

🔒 **Nunca** se guarda el PIN tecleado, ni en claro ni hasheado.

---

# Observaciones del Modelo

Hallazgos de la validación previa. **No proponen cambios estructurales**; son recomendaciones para mejorar la *documentación* y evitar ambigüedades en el desarrollo.

**1. ENUM abiertos con "…".** En el DER, `movimientos_inventario.tipo` y `autorizaciones.tipo` muestran la lista terminada en "…". Para generación de código conviene documentar la lista cerrada y definitiva de cada ENUM (aquí se asentó la versión completa tomada de la especificación funcional); confirmar que no falten valores.

**2. Referencias polimórficas no formales.** `autorizaciones.entidad`/`entidad_id` y `auditoria.entidad`/`entidad_id` apuntan a distintas tablas por convención, sin FK. Es un patrón válido, pero debe documentarse explícitamente qué valores puede tomar `entidad` y a qué tabla mapea cada uno, ya que la base no lo garantiza.

**3. Doble vía de vínculo de autorización.** Una cancelación/ajuste queda enlazada tanto por `entidad`/`entidad_id` (en `autorizaciones`) como por `id_autorizacion` (en `detalle_orden` y `movimientos_inventario`). Conviene documentar cuál es la dirección canónica de consulta para evitar interpretaciones divergentes.

**4. Inconsistencia de género en banderas.** Conviven `activo` (usuarios, productos, proveedores, tipos_orden, tipos_pago, categorias) y `activa` (mesas, impresoras). Es solo nomenclatura, pero impacta la consistencia de atributos en el ORM y en el código generado; documentar la convención esperada.

**5. `disponible` vs `activo` en productos.** `productos` tiene `disponible` (visible en venta) y además soft delete. Son conceptos distintos (disponibilidad temporal vs. baja lógica); documentar la diferencia para que no se usen indistintamente.

**6. Nombres de costo similares.** `productos.costo_referencia` (para margen) y `insumos.costo_unitario` / `movimientos_inventario.costo_unitario` (costo de inventario) son conceptos diferentes con nombres parecidos; documentar la distinción para reportes.

**7. `stock_actual` como cache.** El campo está anotado como cache y su fuente de verdad es `movimientos_inventario`. Debe documentarse el procedimiento de reconciliación y advertir que no debe escribirse stock sin un movimiento asociado.

**8. Campo `propina` en `pagos`.** Decisión ratificada: en V1 **no se registra propina** (es del mesero/personal); la columna se conserva **reservada para V2**. Ningún proceso de V1 la escribe ni la reporta. Documentado para que no se use por error.

**9. Reconciliación roles ↔ Spatie — RESUELTA (2026-08-14).** La ambigüedad de V1.2 queda cerrada: **la tabla `roles` ES la de Spatie** (con *teams*, `id_establecimiento` como ámbito), no un catálogo de dominio aparte. `usuarios.id_rol` es **cache** del rol principal y la verdad vive en `model_has_roles`. Que ambos coincidan es un invariante del sistema (verificado sin divergencias el 2026-08-05); quien asigne rol debe escribir en los dos lados. **Deuda abierta:** falta un test que blinde ese invariante para que no reaparezca por código.

**10. Campos agrupados en el diagrama.** Líneas como `descuento / subtotal / impuesto / total`, `created_at / updated_at / deleted_at`, `remember_token / email_verified_at` y `abierta_at / cerrada_at` representan **varias columnas**. Esta documentación ya las separa; mantener ese criterio en cualquier futura regeneración del diagrama.

**11. Índices implícitos de FK.** El diagrama explicita las restricciones UNIQUE relevantes, pero en PostgreSQL las claves foráneas no generan índice automáticamente. Conviene documentar (a nivel de implementación) los índices sobre columnas FK y sobre `id_establecimiento`, dado que sostienen el aislamiento y los reportes.
