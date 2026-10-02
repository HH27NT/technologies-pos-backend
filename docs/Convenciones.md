# Convenciones de Desarrollo — SaaS POS Multi-Tenant (MVP V1)

**Producto:** SaaS POS Multi-Tenant para bares, cervecerías, cantinas, cafeterías y restaurantes pequeños
**Base documental:** DER V1.2 (22 tablas) · Diccionario de Datos V1.2 · Especificación Funcional MVP V1 · Arquitectura Backend MVP V1
**Stack:** Laravel 12 · PostgreSQL 15+ · Laravel Sanctum · spatie/laravel-permission · React · Vite · Material UI · React Query · React Router
**Carácter:** **OBLIGATORIO** para todos los desarrolladores del proyecto.

> **Propósito.** Este documento define *cómo* se escribe el código del proyecto, no *qué* hace el sistema. No modifica la arquitectura aprobada ni el DER; los formaliza como norma. Cuando una convención ya está fijada por los documentos base (nombres de tablas, sufijos de servicios, estructura de carpetas, excepciones de dominio), aquí se declara obligatoria. Cualquier excepción a estas convenciones requiere aprobación explícita del líder técnico y debe documentarse en el Pull Request correspondiente.

---

## SECCIÓN 1 — ESTÁNDARES GENERALES

### 1.1 Idioma

El proyecto usa un **modelo bilingüe con frontera clara**, tal como ya lo establecen el DER y la Arquitectura:

| Ámbito | Idioma | Razón |
|---|---|---|
| **Dominio de negocio** (tablas, columnas, modelos, servicios, eventos, permisos, rutas de API, mensajes al usuario) | **Español** | El DER, el diccionario y la matriz de permisos ya están en español (`establecimientos`, `OrdenPagada`, `caja.abrir`). El negocio habla español; el código del dominio refleja al negocio. |
| **Infraestructura técnica** (métodos del framework, traits genéricos, configuración, dependencias) | **Inglés** | Laravel, React y las librerías son en inglés; forzar traducciones de `index`, `store`, `update` genera fricción con el framework. |

Reglas obligatorias:

1. Los **conceptos del dominio nunca se traducen**: una orden es `Orden`, no `Order`; una sesión de caja es `SesionCaja`, no `CashSession`. La traducción parcial es el peor escenario (mezclas como `OrderCaja` están prohibidas).
2. Los **métodos estándar de Laravel** conservan su nombre en inglés (`index`, `show`, `store`, `update`, `destroy` en controllers; `viewAny`, `create`, `update`, `delete` en policies). Los métodos de negocio propios se nombran en español (`abrir`, `cerrar`, `cobrar`, `anular`, `resolver`).
3. **Comentarios y PHPDoc/JSDoc:** en español. Mensajes de commit: en español (ver Sección 9).
4. **Mensajes de error visibles al usuario:** en español, exactamente los definidos en la Especificación Funcional (Fase 4) y la tabla de excepciones de dominio (§20 de Arquitectura). No se inventan redacciones nuevas para errores ya definidos.
5. **Sin acentos ni eñes en identificadores de código y base de datos** (`ordenes`, no `órdenes`). Los acentos sí se usan en strings visibles al usuario, comentarios y documentación.

### 1.2 Naming conventions (resumen normativo)

| Elemento | Convención | Ejemplo |
|---|---|---|
| Tablas | `snake_case`, plural, español | `sesiones_caja`, `movimientos_inventario` |
| Columnas | `snake_case`, singular, español | `monto_inicial`, `stock_resultante` |
| Llaves foráneas | prefijo `id_` + entidad en singular | `id_establecimiento`, `id_usuario_apertura` |
| Modelos Eloquent | `PascalCase`, singular, español | `SesionCaja`, `MovimientoInventario` |
| Controllers | `PascalCase` + sufijo `Controller` | `OrdenController`, `SesionCajaController` |
| Services | `Verbo` + `Entidad` + sufijo `Service` | `AbrirCajaService`, `RegistrarPagoService` |
| Form Requests | `Verbo` + `Entidad` + sufijo `Request` | `CrearOrdenRequest`, `CerrarCajaRequest` |
| API Resources | `Entidad` + sufijo `Resource` | `OrdenResource`, `PagoResource` |
| Policies | `Entidad` + sufijo `Policy` | `OrdenPolicy`, `AutorizacionPolicy` |
| Eventos | sustantivo + participio (hecho consumado) | `OrdenPagada`, `CajaCerrada`, `StockBajoDetectado` |
| Listeners | verbo en infinitivo (acción a ejecutar) | `DescontarInventario`, `LiberarMesa`, `RegistrarAuditoria` |
| Jobs | `Verbo` + objeto + sufijo `Job` | `ImprimirTicketJob`, `GenerarReporteExportJob` |
| Middleware | `Verbo` + objeto, `PascalCase` | `ResolveTenant`, `EnsureCajaAbierta` |
| Traits / Concerns | adjetivo o frase de capacidad | `Auditable`, `BelongsToTenant`, `GeneraFolio` |
| Excepciones | descripción + sufijo `Exception` | `CajaCerradaException`, `MesaOcupadaException` |
| Permisos (Spatie) | `entidad.accion` en minúsculas | `caja.abrir`, `ordenes.aplicar_descuento` |
| Acciones de auditoría | `entidad.accion_en_pasado` | `caja.cerrada`, `orden.anulada`, `ticket.reimpreso` |
| Variables PHP | `camelCase` | `$montoInicial`, `$sesionCaja` |
| Constantes / enums | `MAYUSCULAS_SNAKE` o `PascalCase` (enum nativo) | `ESTADO_ABIERTA`, `EstadoOrden::Abierta` |
| Componentes React | `PascalCase` | `PantallaVenta`, `BandejaAutorizaciones` |
| Hooks React | prefijo `use` + `camelCase` | `useOrdenes`, `useSesionCaja` |

### 1.3 Nombres de archivos

- **PHP:** un archivo por clase; el archivo se llama exactamente igual que la clase, `PascalCase.php` (`AbrirCajaService.php`, `OrdenPolicy.php`). PSR-4 estricto.
- **Migraciones:** convención de Laravel con timestamp + acción en inglés técnico y nombre de tabla en español: `2026_01_15_000001_create_sesiones_caja_table.php`, `..._add_aplica_impuesto_to_configuracion_establecimiento_table.php`.
- **Seeders:** `PascalCase` + sufijo `Seeder`, los ya definidos por la arquitectura: `RolesPermisosSeeder.php`, `CatalogosGlobalesSeeder.php`, `SuperAdminSeeder.php`.
- **Tests:** nombre de la clase probada + sufijo según tipo: `AbrirCajaServiceTest.php` (unit), `CerrarCajaTest.php` (feature). Métodos de test descriptivos en `snake_case`: `test_no_permite_segunda_caja_abierta`.
- **React:** componentes en `PascalCase.jsx` (`PantallaVenta.jsx`); hooks en `camelCase.js` (`useOrdenes.js`); utilidades en `camelCase.js`.
- **Documentación:** `PascalCase.md` siguiendo el patrón ya establecido (`DER.md`, `Convenciones.md`, `RoadmapImplementacion.md`).

### 1.4 Nombres de carpetas

El backend sigue **exactamente** la estructura de la Arquitectura §3; no se crean carpetas fuera de ella sin aprobación:

- `app/Models/` — plano, sin subcarpetas: un modelo por tabla del DER.
- `app/Domain/{Módulo}/{Services|Events|DataObjects|Queries|Exporters}/` — módulos en español y `PascalCase`: `Caja`, `Ordenes`, `Pagos`, `Inventario`, `Productos`, `Autorizaciones`, `Establecimientos`, `Usuarios`, `Impresion`, `Reportes`, `Auditoria`.
- `app/Http/Controllers/Api/V1/` — controllers versionados; `app/Http/Requests/`; `app/Http/Resources/`; `app/Http/Middleware/`.
- `app/Policies/`, `app/Listeners/`, `app/Jobs/`.
- `app/Support/{Tenant|Concerns|Exceptions}/`.
- `database/{migrations|seeders|factories}/`.

Carpetas en inglés cuando son del framework (`Http`, `Models`, `Policies`, `Jobs`); módulos de dominio en español. Esa frontera es intencional y obligatoria.

---

## SECCIÓN 2 — BACKEND (LARAVEL)

Principio rector (Arquitectura §1 y §11): **los Controllers orquestan, los Models definen datos, los Services ejecutan reglas dentro de transacciones**. Toda capa tiene responsabilidades acotadas y prohibiciones explícitas.

### 2.1 Controllers

**Responsabilidades.** Autorizar (vía policy), validar la forma (delegando al Form Request), armar el DTO, invocar **un** servicio de dominio y devolver un API Resource con el código HTTP correcto. Un controller por recurso, bajo `Api/V1`.

**NO debe:**
- Contener lógica de negocio, cálculos, ni reglas de estado (caja abierta, mesa ocupada, saldo).
- Abrir transacciones ni tocar la base de datos directamente (sin `DB::`, sin queries Eloquent de escritura).
- Emitir eventos de dominio ni escribir auditoría (eso lo hacen los servicios).
- Devolver modelos crudos: toda salida pasa por un API Resource.
- Invocar más de un servicio de escritura por acción (si una acción necesita dos servicios, falta un servicio orquestador en el dominio).

### 2.2 Services

**Responsabilidades.** Una única responsabilidad de negocio por servicio. Recibe un DTO, **envuelve toda su lógica en `DB::transaction`**, valida el estado de negocio (lanzando excepciones de dominio de §20), aplica bloqueos pesimistas donde la arquitectura los exige (apertura de caja, orden sobre mesa, cierre de caja), persiste y **emite eventos de dominio**. Los servicios canónicos son los del catálogo de la Arquitectura §11 (`AbrirCajaService`, `CerrarCajaService`, `CrearOrdenService`, `RegistrarPagoService`, `DescontarInventarioService`, etc.).

**NO debe:**
- Conocer HTTP: nada de `Request`, `Response`, códigos de estado ni sesión. Un servicio debe poder invocarse desde un comando, un job o un test sin cambios.
- Validar *forma* (longitudes, formatos, obligatoriedad): eso es del Form Request.
- Autorizar acceso por rol/permiso: eso es de policies y middleware. El servicio valida *estado*, no *identidad*.
- Llamar a otros controllers o devolver Resources.
- Saltarse el `TenantContext`: nunca recibir `id_establecimiento` "a mano" desde el cliente para escribir datos de otro tenant.

### 2.3 Policies

**Responsabilidades.** Autorización de *acceso* a nivel de acción/recurso, una policy por modelo gobernado (catálogo de §9). Los métodos mapean a `can('permiso')` de Spatie. `Gate::before` concede todo al super_admin.

**NO debe:**
- Contener reglas de *estado de negocio* (que la caja esté abierta o la mesa libre se valida en el servicio, no en la policy).
- Consultar datos pesados ni ejecutar lógica con efectos secundarios.
- Hardcodear roles cuando exista un permiso: se verifica el permiso (`ordenes.cobrar`), no el nombre del rol, salvo en las reglas que la arquitectura define explícitamente por rol.

### 2.4 Form Requests

**Responsabilidades.** Validación de *forma* de cada endpoint de escritura: obligatoriedad, formato, rangos, no-negatividad y **unicidad por tenant** usando el `id_establecimiento` del `TenantContext` (§17). Mensajes de error exactamente los de la Fase 4 de la Especificación. Reglas ratificadas que viven aquí: motivo obligatorio al cerrar caja con diferencia (P5), tasa de impuesto válida en configuración (P11).

**NO debe:**
- Validar estado de negocio (saldo, caja abierta, solicitud ya resuelta): eso lanza excepciones de dominio desde el servicio.
- Mutar datos ni ejecutar lógica de persistencia.
- Hacer la autorización fina del recurso (la hace la policy); el `authorize()` del Request puede delegar a la policy, nunca duplicar su lógica.

### 2.5 Events (eventos de dominio)

**Responsabilidades.** Representar **hechos consumados** del negocio, nombrados en pasado (`OrdenPagada`, `CajaCerrada`, `AutorizacionResuelta`). Los emiten únicamente los servicios, dentro de su transacción. El catálogo válido es el de la Arquitectura §12; agregar un evento nuevo requiere actualizar ese catálogo.

**NO debe:**
- Contener lógica: un evento es un portador de datos inmutable (la entidad o su identificador y el contexto mínimo).
- Usarse como mecanismo de "llamada a función" para flujos que no son hechos de negocio.
- Emitirse desde controllers, listeners o jobs.

### 2.6 Listeners

**Responsabilidades.** Reaccionar a eventos según la tabla de §13, respetando su **modo**: `RegistrarAuditoria`, `DescontarInventario`, `RevertirInventario` y `LiberarMesa` son **síncronos** (misma transacción); `ImprimirTicket`, `EnviarComanda` y `NotificarStockBajo` se **encolan**. Un listener delgado que delega en el servicio correspondiente.

**NO debe:**
- Cambiar el modo definido: encolar la auditoría financiera o hacer síncrona la impresión viola la arquitectura (la impresión **nunca** bloquea el cobro).
- Contener reglas de negocio propias: delega en servicios.
- Emitir nuevos eventos que generen cascadas no documentadas en §12.

### 2.7 Jobs

**Responsabilidades.** Trabajo diferido en cola según la tabla de §14: `ImprimirTicketJob` y `EnviarComandaJob` (cola `impresion`, con reintentos/backoff y fallback a PDF, P15), `GenerarReporteExportJob` (cola `reportes`, idempotente por solicitud), `ReconciliarStockJob` (cola `mantenimiento`).

**NO debe:**
- Ejecutar operaciones financieras o de stock que la arquitectura define como síncronas.
- Asumir estado del momento del despacho: el job revalida lo necesario al ejecutarse.
- Carecer de política de reintentos/backoff cuando la tabla §14 la exige.

### 2.8 Observers

**Responsabilidades.** Implementar la mitad "CRUD sensible" de la auditoría híbrida (§15): los modelos marcados con `Auditable` registran `created/updated/deleted` con diff `datos_antes`/`datos_despues` en JSONB (usuarios, productos, insumos, mesas, configuración, establecimientos, etc.).

**NO debe:**
- Contener lógica de negocio ni efectos colaterales más allá del registro de auditoría.
- Sustituir a los listeners de acciones de negocio (abrir caja, cobrar orden): esas se auditan vía eventos, no vía observer.
- Bloquear o abortar la operación observada.

### 2.9 Middleware

**Responsabilidades.** La cadena fija de §10, **en este orden**: `auth:sanctum` → `ResolveTenant` → `EnsureTenantActivo` → `role:`/`permission:` (Spatie) → `EnsureCajaAbierta` (solo rutas de venta). `ResolveTenant` fija el `TenantContext` y gestiona la impersonación auditada del super_admin (P17).

**NO debe:**
- Alterar el orden de la cadena ni omitir eslabones en rutas operativas.
- Contener reglas de negocio (más allá de los bloqueos que define: tenant inactivo, caja cerrada).
- Resolver el tenant desde parámetros del cliente, salvo el override de impersonación explícito, validado contra el rol y auditado.

### 2.10 Models

**Responsabilidades.** Eloquent puro: datos, relaciones, casts (JSONB→array, montos→`decimal:2/3`, timestamps→`datetime`, booleanos→`bool`) y traits (`BelongsToTenant`, `Auditable`, `SoftDeletes`, `HasApiTokens`, `HasRoles`, `IncluyeGlobales` según la tabla de §5). Un modelo por tabla del DER, sin excepciones.

**NO debe:**
- Contener lógica de negocio (regla explícita de §5: "sin lógica de negocio en los modelos"). Nada de métodos `cobrar()`, `cerrar()` en el modelo.
- Definir scopes que rompan el aislamiento: `withoutGlobalScope(TenantScope::class)` está prohibido fuera de `Support/Tenant` y del contexto super_admin.
- Exponerse directamente en respuestas (siempre detrás de un Resource).
- Editar o borrar filas de tablas append-only (`movimientos_inventario`, `auditoria`): las correcciones se hacen con movimientos inversos.

### 2.11 Traits / Concerns

**Responsabilidades.** Capacidades transversales reutilizables ya definidas: `BelongsToTenant` (agrega el tenant, aplica `TenantScope`, autollena al crear), `IncluyeGlobales` (scope híbrido de `unidades_medida`: propias + globales), `Auditable` (captura antes/después), `GeneraFolio` (correlativo continuo por establecimiento, P12).

**NO debe:**
- Convertirse en cajón de sastre: un trait con responsabilidades mixtas se divide.
- Duplicar lógica que pertenece a un servicio.
- Crearse nuevos traits de tenant o auditoría paralelos a los existentes.

---

## SECCIÓN 3 — BASE DE DATOS

El esquema es el del DER V1.2 y el Diccionario de Datos; estas convenciones lo norman, no lo modifican.

### 3.1 Nombres de tablas

- `snake_case`, **plural**, en español: `establecimientos`, `ordenes`, `sesiones_caja`, `movimientos_inventario`.
- Tablas puente: nombre que exprese la relación con atributos, como ya hace `recetas_producto` (producto↔insumo con `cantidad`).
- Tablas 1:1: singular del concepto dependiente + entidad, como `configuracion_establecimiento`.
- Sin prefijos de aplicación (`tbl_`, `app_`). Las tablas de Spatie conservan sus nombres de paquete (configuradas con `teams`).

### 3.2 Nombres de columnas

- `snake_case`, singular, en español: `monto_inicial`, `stock_actual`, `orden_display`.
- Booleanos como adjetivo o bandera afirmativa: `activo`, `activa`, `disponible`, `aplica_impuesto`, `controla_inventario`, `impresion_automatica`, `enviado`. Nunca negaciones (`no_activo`).
- Timestamps de ciclo de vida: `created_at`, `updated_at`, `deleted_at` (inglés, estándar Laravel).
- Timestamps de negocio: participio + `_at` según el patrón ya fijado por el DER: `abierta_at`, `cerrada_at`, `pagado_at`, `cancelado_at`, `impreso_at`, `resuelta_at`, `email_verified_at`.
- Montos: `DECIMAL` con la precisión del Diccionario (`DECIMAL(12,2)` dinero, `DECIMAL(12,3)` cantidades de stock, `DECIMAL(5,2)` tasas). **Prohibido** usar `FLOAT`/`DOUBLE` para dinero o stock.
- Estados: columna `estado` (o `estado_item`) con los ENUM exactos del DER: `abierta|cerrada` (caja), `abierta|pagada|anulada` (orden), `activo|cancelado` (ítem), `pendiente|aprobada|rechazada` (autorización). No se agregan valores sin cambio ratificado del DER.
- JSON: tipo `JSONB` (`contenido_json`, `datos_antes`, `datos_despues`).

### 3.3 Llaves foráneas

- Prefijo **`id_`** + entidad referenciada en singular: `id_establecimiento`, `id_orden`, `id_tipo_pago`. Esta es la convención del DER y **prevalece sobre el default de Laravel** (`establecimiento_id`); los modelos declaran la clave foránea explícitamente en sus relaciones.
- FKs con rol distinguible llevan el rol como sufijo: `id_usuario_apertura`, `id_usuario_cierre`, `id_usuario_solicita`, `id_usuario_autoriza`.
- Toda tabla operativa lleva `id_establecimiento` (discriminador de tenant); solo las tablas marcadas **(GLOBAL)** en el DER (`tipos_orden`, `tipos_pago`, `roles`) y las nullable documentadas (`unidades_medida` híbrido, `usuarios` super_admin, `auditoria`) se apartan de la regla.
- `ON DELETE`: restrictivo por defecto. El único `CASCADE` autorizado es `detalle_orden.id_orden` (definido en el DER). Las referencias polimórficas `entidad` + `entidad_id` (`autorizaciones`, `auditoria`) **no** se materializan como FK formal.
- Toda FK se indexa.

### 3.4 Soft deletes

- Aplican **solo** donde el DER define `deleted_at`: `establecimientos`, `usuarios`, `mesas`, `impresoras`, `categorias_producto`, `productos`, `proveedores`, `insumos`.
- Las entidades maestras **no se eliminan físicamente** (regla global 30): se desactivan o se soft-deletean según el caso de uso.
- **Nunca** se agrega soft delete a tablas transaccionales o append-only (`ordenes` usa estado `anulada`; `movimientos_inventario` y `auditoria` son inmutables; las correcciones de stock son movimientos inversos).
- Prohibido `forceDelete()` en código de aplicación.

### 3.5 Índices

Obligatorios desde la migración inicial (no se posponen):

- **PK** `BIGINT` autoincremental llamada `id` en todas las tablas.
- **Índice en toda FK**, encabezando por `id_establecimiento` en los compuestos.
- **Unicidades por tenant** definidas en el DER: `UNIQUE(id_establecimiento, folio)` en órdenes; `UNIQUE(id_establecimiento, numero)` en mesas; `UNIQUE(id_establecimiento, email)` y `UNIQUE(id_establecimiento, username)` en usuarios; `UNIQUE(id_establecimiento)` en configuración (1:1); `UNIQUE(id_producto, id_insumo)` en recetas.
- **Índices únicos parciales** que respaldan el estado de negocio en la base (§1.5 y §19 de Arquitectura): una sola sesión `abierta` por establecimiento; una sola orden `abierta` por mesa.
- Índice parcial de stock bajo (`stock_actual <= stock_minimo`) para el reporte de inventario (§16).
- Nombres de índices descriptivos: `uq_{tabla}_{columnas}`, `idx_{tabla}_{columnas}`, `uq_{tabla}_{regla}_parcial`.

### 3.6 Migraciones

- Una migración por tabla en la creación inicial, en el **orden de dependencias de FKs** del DER (padres antes que hijos).
- Las migraciones incluyen **todo** lo declarativo de la tabla: columnas, FKs, unicidades, índices parciales, defaults. Los índices parciales de PostgreSQL se crean en la migración aunque requieran sentencias específicas del motor.
- Toda migración define `down()` funcional y reversible.
- **Nunca se edita una migración ya mergeada a `develop`:** los cambios posteriores son nuevas migraciones (`add_`, `alter_`, `drop_`).
- Cambios de esquema solo proceden de un cambio ratificado del DER/Diccionario; la migración referencia la versión del DER en su descripción o comentario.
- Seeders idempotentes (re-ejecutables sin duplicar): catálogos globales con tenant nulo (`tipos_orden`, `tipos_pago`, unidades predefinidas), roles/permisos y super_admin inicial.

---

## SECCIÓN 4 — API

### 4.1 Principios

- Toda la API vive bajo el prefijo versionado **`/api/v1/`**.
- Recursos en **español, plural, kebab-case** cuando hay palabras compuestas: `/api/v1/ordenes`, `/api/v1/sesiones-caja`, `/api/v1/unidades-medida`.
- Verbos HTTP semánticos: `GET` lectura, `POST` creación y acciones de negocio, `PUT/PATCH` actualización, sin `DELETE` físico para maestros (se usa desactivación/soft delete vía `PATCH`).
- Acciones de negocio como sub-recurso verbal: `POST /ordenes/{id}/pagos`, `POST /caja/abrir`, `PATCH /autorizaciones/{id}/aprobar`. No se inventan endpoints fuera de los casos de uso de la Especificación.
- Toda salida pasa por **API Resources** (§18): nunca se exponen columnas internas (`password_hash`, `remember_token`) ni modelos crudos.

### 4.2 Formato estándar de respuesta

Todas las respuestas JSON del API siguen esta envoltura única:

**Éxito (operación simple):**

```json
{
  "success": true,
  "message": "Caja abierta correctamente.",
  "data": { }
}
```

**Éxito (colección paginada):**

```json
{
  "success": true,
  "message": "",
  "data": [ ],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 120,
    "last_page": 8
  },
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  }
}
```

**Error:**

```json
{
  "success": false,
  "message": "La mesa ya tiene una orden abierta.",
  "errors": { }
}
```

Reglas:
- `success` es booleano y obligatorio en toda respuesta.
- `message` es legible para el usuario final, en español, tomado de los mensajes oficiales (Fase 4 / §20). Puede ser cadena vacía en lecturas.
- `data` contiene el recurso o colección (objeto, arreglo o `null`).
- `errors` solo aparece en fallos de validación: mapa `campo → [mensajes]` tal como lo produce el Form Request.
- No se agregan claves ad-hoc fuera de esta envoltura sin actualizar esta convención.

### 4.3 Errores y códigos HTTP

El mapeo es el de la tabla de excepciones de dominio (§20) y es **obligatorio**:

| Situación | HTTP |
|---|---|
| Éxito en lectura/actualización | 200 |
| Recurso creado | 201 |
| Error de validación de forma (`ValidationException` del Form Request) | 422 |
| Estado de negocio inválido de entrada (`PagoInsuficienteException`, `MotivoDiferenciaRequeridoException`) | 422 |
| No autenticado | 401 |
| Sin permiso / requiere autorización (`RequiereAutorizacionException`) | 403 |
| Recurso inexistente o de otro tenant | 404 |
| Conflicto de estado (`CajaYaAbiertaException`, `MesaOcupadaException`, `OrdenNoModificableException`, `AutorizacionResueltaException`, `OrdenesAbiertasException`, `CajaCerradaException`) | 409 |
| Establecimiento desactivado (`EstablecimientoInactivoException`, P19) | 423 |
| Error interno no controlado | 500 |

Reglas obligatorias:
- Un recurso de **otro tenant** responde **404**, nunca 403: no se revela la existencia de datos ajenos.
- Los mensajes de las excepciones de dominio son exactamente los de §20 ("Abre la caja para poder vender.", "Ya hay una caja abierta.", etc.).
- Los errores 500 jamás exponen trazas, SQL ni rutas internas en producción.

### 4.4 Validaciones

- Toda escritura pasa por Form Request; la envoltura de error 422 lleva `errors` con el detalle por campo y los mensajes oficiales de la Fase 4.
- La unicidad por tenant se valida con el `id_establecimiento` del `TenantContext`, nunca con uno recibido del cliente.
- La validación de estado de negocio responde con la excepción de dominio y su código (§4.3), sin estructura `errors` por campo.

### 4.5 Paginación

- Toda colección se devuelve **paginada** (§18), formato de §4.2.
- Parámetros: `?page=` y `?per_page=`; `per_page` por defecto **15**, máximo **100**.
- No existen endpoints "traer todo" para tablas operativas.

### 4.6 Filtros

- Por query string, con el nombre de la columna o concepto en español: `?estado=abierta`, `?tipo=merma`, `?id_categoria=3`, `?desde=2026-01-01&hasta=2026-01-31`, `?buscar=` para búsqueda textual simple.
- La búsqueda textual (`?buscar=`) se resuelve con `ApiController::buscarEn()`: compara en minúsculas (`LOWER(columna) LIKE ?`) porque LIKE distingue mayúsculas en PostgreSQL, agrupa el OR entre columnas para no escaparse de los demás filtros, y escapa los comodines del término con `ESCAPE '!'`. Implementado en M06 productos (`nombre`, `sku`) desde 2026-09-03.
- **Relaciones bajo demanda.** Cuando una pantalla necesita una relación pesada que el resto no usa, el índice la carga con un parámetro explícito (`?con_recetas=1` en M06, desde 2026-09-10) y el Resource la expone con `whenLoaded`: sin el parámetro la clave **no aparece**, y con él, si no hay filas, viene **vacía**. Esa diferencia es la que deja distinguir "no tiene" de "no se pidió". Se prefiere esto a que el cliente cruce dos índices paginados por distinta unidad: `GET /recetas` pagina por renglón, y cruzarlo contra productos hacía que una receta larga se partiera entre páginas y el producto de la siguiente pareciera no tener ninguna.
- Los filtros de **ausencia** se nombran por lo que falta (`?sin_receta=1`, con `whereDoesntHave`). Su conteo se obtiene pidiendo `per_page=1` y leyendo `meta.total`: un aviso de "faltan N" no puede costar traerse el catálogo.
- Las fechas de filtros se interpretan en la **zona horaria del establecimiento** (regla de reportes, §16) y se transmiten en formato `YYYY-MM-DD` (ISO 8601).
- Solo se filtran columnas explícitamente permitidas por el endpoint (lista blanca); los filtros desconocidos se ignoran, no fallan.
- El filtro por tenant **no es un parámetro**: lo impone el `TenantScope`. Cualquier `?id_establecimiento=` del cliente se ignora (salvo impersonación de super_admin por el canal definido en §7 de Arquitectura).

### 4.7 Ordenamientos

- Parámetros `?sort=` (columna) y `?direction=asc|desc`; default: la clave temporal natural del recurso descendente (`created_at` o el `*_at` de negocio).
- Lista blanca de columnas ordenables por endpoint; columna no permitida → se ignora y aplica el default.

---

## SECCIÓN 5 — AUTENTICACIÓN Y PERMISOS

### 5.1 Sanctum

- Autenticación por **tokens de Sanctum**; toda ruta de API (excepto login y recuperación de contraseña) va detrás de `auth:sanctum`.
- Los tokens **no caducan por inactividad** (decisión P18). Compensación obligatoria: **revocación explícita de tokens** al desactivar un usuario o un establecimiento, y en el logout.
- Las credenciales son únicas **por establecimiento** (email/username compuestos con el tenant); el login resuelve usuario + tenant + rol.
- Un usuario `activo = false` o de establecimiento desactivado no inicia sesión (regla global 3); los mensajes son los de la Fase 4.
- Política de contraseñas (P16 ratificado): longitud **mínima de 8 caracteres**, **sin** bloqueo por intentos fallidos, implementada como configurable.

### 5.2 Spatie Permission

- Spatie es **la autoridad** de roles y permisos (§8). La tabla `roles` del DER se reconcilia con la de Spatie; `usuarios.id_rol` es **cache del rol principal**; la verdad vive en `model_has_roles`.
- Se usa la función **teams** con `team_foreign_key = id_establecimiento`: ser ADMIN del establecimiento A no otorga nada en el B. Todo código que consulte roles/permisos debe operar con el team del `TenantContext` fijado.
- Roles semilla: `super_admin` (global, sin team), `admin`, `operador`. **No se crean roles nuevos** en V1.
- El catálogo de permisos es el de §8 (`caja.abrir`, `ordenes.cobrar`, `inventario.merma`, `tickets.reimprimir`, etc.); agregar un permiso requiere actualizar el catálogo y el seeder.
- El código verifica **permisos**, no roles, salvo donde la matriz define la regla por rol.

### 5.3 Policies

- Una policy por modelo gobernado, registrada y exhaustiva según la tabla de §9.
- Las policies autorizan **acceso**; el **estado** lo validan los servicios (separación obligatoria de §9/§17).
- El flujo 🔐 "requiere autorización" **no es un permiso especial**: el OPERADOR tiene el permiso de *solicitar*; el ADMIN el de *ejecutar/aprobar*. El OPERADOR **nunca** posee de forma directa `ordenes.cancelar_item`, `ordenes.anular`, `inventario.entrada` ni `inventario.ajustar`.

### 5.4 Gates

- `Gate::before` concede todo al `super_admin` (regla única y global). No se agregan otros `Gate::before`/`after`.
- La impersonación del super_admin (P17) pasa por el canal del middleware `ResolveTenant`, queda **marcada y auditada** (`soporte.impersonacion`); nunca se implementa "a mano" alterando el token o el contexto.

### 5.5 Reglas obligatorias de protección de endpoints

Toda ruta operativa cumple, sin excepción, la cadena de §10 **en este orden**:

1. `auth:sanctum` — nadie anónimo.
2. `ResolveTenant` — el contexto de tenant queda fijado antes de cualquier query.
3. `EnsureTenantActivo` — tenant desactivado → 423, órdenes congeladas (P19).
4. `role:`/`permission:` de Spatie — autorización gruesa por ruta.
5. `EnsureCajaAbierta` — **solo** en rutas de venta (crear orden, agregar ítems, cobrar).
6. Policy en el controller (`authorize`) — autorización fina por recurso/acción.

Prohibiciones absolutas:
- Ningún endpoint de escritura sin Form Request.
- Ningún endpoint operativo sin policy.
- Ningún query que reciba `id_establecimiento` del cliente para escribir.
- Ningún uso de `withoutGlobalScope(TenantScope::class)` fuera de la infraestructura de tenant y del flujo super_admin.

---

## SECCIÓN 6 — AUDITORÍA

### 6.1 Qué se audita (obligatorio)

La población es **híbrida** (§15) y su cobertura es obligatoria:

**A) CRUD sensible — vía Observer + trait `Auditable`:** altas, ediciones y bajas de usuarios, productos, insumos, mesas, configuración del establecimiento, y demás modelos marcados `Auditable` en la tabla de §5 (establecimientos, etc.).

**B) Acciones de negocio — vía eventos → listener `RegistrarAuditoria`:**
- Apertura y cierre de caja (incluida la diferencia y su motivo, P5).
- Creación, cobro y anulación de órdenes; cancelación de ítems.
- Pagos registrados.
- Movimientos de inventario: entradas, ajustes, mermas, roturas, consumo interno (regla global 19).
- Solicitud y resolución de autorizaciones.
- Altas/bajas/cambios de rol de usuarios.
- **Impersonación del super_admin** (P17) — siempre.
- **Reimpresión de tickets** (P14) — se permite sin autorización, pero **siempre se audita**.

### 6.2 Qué información se guarda

Cada registro de `auditoria` contiene, conforme al DER y §15:

| Campo | Contenido |
|---|---|
| `id_establecimiento` | Tenant de la acción; **NULL** para acciones del super_admin a nivel plataforma |
| `id_usuario` | Quién ejecutó la acción (nullable solo en acciones de sistema) |
| `accion` | Acción **semántica** en formato `entidad.accion`: `caja.cerrada`, `orden.anulada`, `soporte.impersonacion`, `ticket.reimpreso` |
| `entidad` | Nombre de la entidad afectada (referencia polimórfica) |
| `entidad_id` | Identificador de la entidad afectada |
| `datos_antes` | Estado previo (JSONB, diff) — `null` en creaciones |
| `datos_despues` | Estado posterior (JSONB, diff) — `null` en eliminaciones |
| `ip` | IP de origen de la petición |
| `created_at` | Momento del registro |

### 6.3 Formato y reglas estándar

- La tabla es **append-only**: prohibido editar o borrar registros de auditoría desde la aplicación.
- `datos_antes`/`datos_despues` guardan el **diff** de campos modificados (no el modelo completo), excluyendo siempre campos sensibles (`password_hash`, `remember_token`) y ruido (`updated_at`).
- Las acciones se nombran en pasado, español, formato `entidad.accion` (consistente con §15).
- **Consistencia transaccional obligatoria:** la auditoría de acciones financieras (caja, órdenes, pagos, stock) se escribe **dentro de la misma transacción** que la acción; si la transacción se revierte, la auditoría también.
- Consulta: el ADMIN ve la de su tenant (vía scope); el SUPER_ADMIN ve todo, incluido tenant nulo; el OPERADOR no consulta auditoría. Filtros disponibles: fecha, usuario, acción, entidad.

---

## SECCIÓN 7 — TESTING

Alineado con la Estrategia de Pruebas de la Arquitectura (§22). Ningún Pull Request se aprueba con pruebas obligatorias faltantes o en rojo.

### 7.1 Unit Tests

**Qué son aquí:** pruebas de servicios de dominio y componentes de soporte en aislamiento (sin HTTP).

**Obligatorio probar:**
- **Arqueo de caja:** `monto_sistema` solo con efectivo (P6, P7); cálculo de diferencia; motivo obligatorio si la diferencia ≠ 0 (P5).
- **Totalizador:** impuesto configurable **sumado aparte** sobre precio sin impuesto (P11); descuentos del cajero (P10).
- **Descuento de inventario al cobrar** (P1): solo productos con `controla_inventario` y receta; stock negativo permitido con alerta (P2).
- **Reversa de movimientos:** solo sobre movimientos de origen `venta` (P3); nunca manuales.
- **Pago dividido por monto** hasta saldar (P8); sin propina (P9); cambio en sobrepago de efectivo.
- **Folio continuo por tenant** (P12), sin reinicio ni duplicado.
- **Reglas de servicios:** último ADMIN activo no desactivable; solicitud de autorización resuelta no cambia de estado; el ledger actualiza `stock_actual`/`stock_resultante` en la misma operación.
- Scopes de tenant (`TenantScope`, `IncluyeGlobales`) y autollenado del discriminador.

### 7.2 Feature Tests

**Qué son aquí:** pruebas HTTP de endpoints completos con Sanctum, atravesando middleware, Form Request, policy, servicio y Resource.

**Obligatorio probar:**
- **Matriz de permisos completa:** cada rol × cada acción de la Fase 7, incluidos los flujos 🔐 (operador solicita / admin aprueba) y los ❌ (operador no accede a usuarios, auditoría, autorizar).
- **Autenticación:** login válido/inválido; usuario inactivo y establecimiento desactivado no entran; logout revoca el token.
- **Validaciones de forma:** los mensajes y casos límite de la Fase 4 por pantalla/endpoint (unicidades por tenant, cantidades > 0, montos ≥ 0, motivo obligatorio).
- **Flujos de los casos de uso CU-01 a CU-19** en su flujo principal y sus excepciones declaradas (mesa ocupada, caja cerrada, orden pagada no modificable, solicitud ya resuelta...).
- **Envoltura de API:** formato estándar de respuesta, códigos HTTP del mapeo §4.3, paginación.

### 7.3 Integration Tests

**Qué son aquí:** pruebas de comportamiento transversal de varios componentes y de garantías de la base.

**Obligatorio probar:**
- **Aislamiento multi-tenant:** un usuario del establecimiento A nunca obtiene datos de B en ningún recurso; recursos ajenos responden 404; las unidades de medida devuelven globales + propias y el tenant no edita las globales.
- **Concurrencia:** dobles aperturas de caja simultáneas fallan (índice único parcial + bloqueo); dobles órdenes abiertas sobre la misma mesa fallan; idempotencia de pagos ante reintentos.
- **Cadena de eventos del cobro:** `OrdenPagada` → descuento de inventario + liberación de mesa síncronos + impresión encolada; la impresión nunca bloquea el cobro; sin impresora → PDF (P15).
- **Auditoría transaccional:** si la transacción de una acción financiera se revierte, su registro de auditoría también; cobertura de impersonación y reimpresión.
- **Tenant desactivado (P19):** endpoints operativos bloqueados (423) y órdenes congeladas.
- **Ledger:** `stock_actual` coincide con la suma del ledger; la reconciliación recalcula correctamente.

### 7.4 Reglas generales

- Las factories cubren las 22 tablas y respetan el tenant.
- Los tests de aislamiento y concurrencia corren contra **PostgreSQL** (los índices parciales no existen en SQLite); el pipeline de CI usa PostgreSQL.
- Un bug corregido exige su test de regresión en el mismo PR.

---

## SECCIÓN 8 — FRONTEND (REACT · VITE · MATERIAL UI · REACT QUERY · REACT ROUTER)

El frontend consume exclusivamente la API `/api/v1` y replica la frontera de idioma: **dominio en español, infraestructura en inglés**.

### 8.1 Estructura recomendada de carpetas

```
src/
├── api/                 # Cliente HTTP (instancia única), endpoints por módulo
│   ├── cliente.js           # axios/fetch con baseURL /api/v1, token, interceptores
│   └── {modulo}.js          # ordenes.js, caja.js, inventario.js ...
├── app/                 # Composición de la aplicación
│   ├── router.jsx           # definición de rutas (React Router)
│   ├── tema.js              # tema de Material UI
│   └── providers.jsx        # QueryClientProvider, ThemeProvider, AuthProvider
├── auth/                # Contexto de sesión: usuario, rol, permisos, tenant
├── components/          # Componentes compartidos y "tontos" (UI pura)
├── features/            # Un directorio por módulo funcional (M01–M16)
│   ├── caja/
│   │   ├── components/      # componentes propios del módulo
│   │   ├── hooks/           # useSesionCaja, useAbrirCaja ...
│   │   └── pages/           # AperturaCaja.jsx, CierreCaja.jsx, HistoricoCajas.jsx
│   ├── ordenes/  pagos/  inventario/  productos/  mesas/
│   ├── autorizaciones/  auditoria/  reportes/  usuarios/
│   └── plataforma/          # módulo SUPER_ADMIN (M02)
├── hooks/               # hooks transversales (usePermisos, usePaginacion)
└── utils/               # formateo de moneda/fecha (zona horaria del establecimiento)
```

Las páginas corresponden a las **pantallas de la Fase 3** de la Especificación Funcional; no se crean pantallas fuera de ese catálogo.

### 8.2 React

- **Componentes funcionales con hooks**, exclusivamente. `PascalCase` para componentes, prefijo `use` para hooks.
- Separación contenedor/presentación: las *pages* orquestan datos y permisos; los *components* presentan y reciben todo por props.
- El estado de servidor **no** se duplica en estado global propio: vive en React Query (§8.4). El estado local de UI usa `useState`/`useReducer`.
- La sesión (usuario, rol, permisos, establecimiento) vive en un contexto de auth único; los componentes consultan permisos con un hook (`usePermisos`) y **ocultan o deshabilitan** acciones según la matriz de la Fase 7 — entendiendo que la autorización real la impone el backend; el frontend solo refleja.
- Prohibido hardcodear reglas de negocio en el cliente (cálculo de impuestos, arqueo, saldos): el frontend muestra lo que la API calcula. La única aritmética local permitida es de conveniencia visual (p. ej., cálculo de cambio en pantalla de cobro, que el backend no almacena).

### 8.3 Vite

- Variables de entorno con prefijo `VITE_` (`VITE_API_URL`); nunca secretos en el cliente.
- Alias de importación `@/` → `src/`; imports absolutos sobre relativos profundos.
- Code splitting por ruta con `lazy` en las páginas de módulo; build de producción sin sourcemaps públicos.

### 8.4 React Query

- **Toda** lectura de la API pasa por React Query; **toda** escritura por mutaciones. Prohibido `fetch`/`axios` directo en componentes.
- **Claves de query jerárquicas y en español**, espejo del recurso: `['ordenes']`, `['ordenes', id]`, `['ordenes', { estado: 'abierta' }]`, `['caja', 'actual']`, `['reportes', 'ventas', { desde, hasta }]`.
- Tras cada mutación se **invalidan** las claves afectadas (cobrar una orden invalida `['ordenes']`, `['caja', 'actual']`, `['mesas']`, `['reportes', 'dashboard']`).
- Un hook por operación, nombrado por la acción de negocio: `useAbrirCaja`, `useRegistrarPago`, `useResolverAutorizacion`.
- Los errores de la envoltura estándar (§4.2) se manejan en un manejador central: 401 → logout/redirect a login; 403 → notificación de permiso; 409/422/423 → mostrar el `message` oficial del backend tal cual (no se redactan mensajes paralelos).

### 8.5 React Router

- Rutas en español, espejo de los módulos: `/login`, `/dashboard`, `/venta`, `/ordenes`, `/ordenes/:id`, `/caja`, `/inventario`, `/productos`, `/mesas`, `/autorizaciones`, `/auditoria`, `/reportes`, `/configuracion`, `/usuarios`, `/plataforma` (super_admin).
- **Rutas protegidas por defecto:** un guard de autenticación envuelve todo salvo `/login` y recuperación; un guard de rol/permiso protege módulos restringidos (usuarios, auditoría, resolución de autorizaciones, plataforma) según la matriz de la Fase 7.
- La navegación tras login se decide por rol (CU-01: "redirige según rol").
- Estado de filtros/paginación de listados en query params de la URL (compartible y restaurable), con los mismos nombres de parámetros que la API (§4.6–4.7).

### 8.6 Material UI

- **Un tema único** (`app/tema.js`) centraliza paleta, tipografía y espaciado; prohibido el estilo inline ad-hoc y los colores hardcodeados en componentes.
- Se usan los componentes MUI estándar (DataGrid/Table para listados, Dialog para confirmaciones y solicitudes de autorización, Snackbar/Alert para los mensajes de la API).
- Los formularios muestran los errores de validación 422 **por campo**, mapeando la clave `errors` de la envoltura estándar a los `helperText` de los inputs.
- Toda acción destructiva o sensible (anular, cancelar, cerrar caja) pide confirmación explícita en Dialog, mostrando las consecuencias definidas por la Especificación.
- Formato de moneda y fechas con la **moneda y zona horaria del establecimiento** (campos del DER), centralizado en `utils/`.

---

## SECCIÓN 9 — GIT

### 9.1 Estrategia de ramas

Modelo **GitFlow simplificado** con dos ramas permanentes:

| Rama | Propósito | Reglas |
|---|---|---|
| `main` | Código en producción / releases estables | Protegida. Solo recibe merges desde `develop` (release) o `hotfix/*`. Cada merge se etiqueta (`v1.0.0`). |
| `develop` | Integración continua del sprint | Protegida. Solo recibe Pull Requests aprobados. Debe estar siempre en verde (CI). |

Ramas temporales, siempre creadas desde `develop` (salvo hotfix, desde `main`):

- `feature/*` — nueva funcionalidad de un sprint.
- `fix/*` — corrección de bug no urgente.
- `hotfix/*` — corrección urgente sobre producción; se mergea a `main` **y** a `develop`.
- `chore/*` — tareas sin impacto funcional (dependencias, configuración, documentación).

Commits directos a `main` y `develop`: **prohibidos**.

### 9.2 Nombres de ramas

Formato: `tipo/sprint-modulo-descripcion-corta`, en minúsculas, kebab-case, sin acentos:

- `feature/s6-caja-apertura-cierre`
- `feature/s8-pagos-pago-dividido`
- `feature/s9-autorizaciones-bandeja-admin`
- `fix/s7-ordenes-folio-duplicado-concurrencia`
- `chore/ci-pipeline-postgres`

La referencia al sprint (`s0`–`s12`) vincula la rama con el Roadmap de Implementación. Una rama = un alcance acotado; las ramas de larga vida se prohíben (máximo el ciclo del sprint).

### 9.3 Commits

Formato **Conventional Commits con descripción en español**:

```
tipo(ambito): descripcion en imperativo y minusculas

[cuerpo opcional: qué y por qué, no cómo]

[footer opcional: referencia a tarea/decisión, p. ej. "Decision: P5"]
```

- **Tipos permitidos:** `feat`, `fix`, `test`, `refactor`, `chore`, `docs`, `perf`, `ci`.
- **Ámbito:** el módulo de dominio en español: `caja`, `ordenes`, `pagos`, `inventario`, `autorizaciones`, `auditoria`, `auth`, `tenant`, `reportes`, `impresion`, `frontend`, `db`.
- Ejemplos:
  - `feat(caja): exigir motivo al cerrar con diferencia (P5)`
  - `feat(pagos): cierre de orden y evento OrdenPagada al saldar`
  - `fix(ordenes): folio continuo seguro ante concurrencia (P12)`
  - `test(tenant): aislamiento de unidades globales y propias`
  - `docs(api): mapeo de excepciones de dominio a codigos http`
- Un commit = un cambio coherente y compilable. Prohibidos los commits "wip", "cambios", "arreglos varios" en ramas que van a PR (se permiten en local, pero se reescriben antes del PR).
- Cuando un commit materializa una decisión ratificada de la Fase 9, se referencia (`P1`–`P21`) en el título o footer.

### 9.4 Pull Requests

Reglas obligatorias:

1. **Todo cambio entra por PR a `develop`**; nada se mergea sin PR.
2. **Mínimo un revisor aprobando**, distinto del autor. Cambios al esquema de base de datos, a la infraestructura de tenant o a la cadena de middleware requieren además la aprobación del líder técnico.
3. **CI en verde obligatorio:** migraciones, suite completa de tests (contra PostgreSQL) y linters.
4. **Plantilla mínima del PR:**
   - *Qué cambia* y *por qué* (vínculo al sprint y módulo del Roadmap).
   - Decisiones de la Fase 9 que materializa (P1–P21), si aplica.
   - Checklist de Definition of Done del sprint: código funcionando, tests del cambio incluidos, policy implementada, auditoría cableada, validación en Form Request, documentación/contrato actualizado.
   - Notas de migración o seeders si toca el esquema.
5. **Tamaño:** PRs acotados (guía: ≤ ~400 líneas netas de cambio funcional); los cambios grandes se trocean por capa (migraciones → modelos → servicio → endpoint → tests) respetando el orden del Roadmap.
6. **Prohibido mergear un PR que:** edite migraciones ya mergeadas; toque tablas append-only con updates/deletes; exponga modelos sin Resource; agregue endpoints sin Form Request o sin policy; reduzca cobertura de las pruebas obligatorias de la Sección 7.
7. Merge por **squash** hacia `develop` (historial limpio, un commit por PR con título conventional); merge commit normal de `develop` a `main` en releases etiquetados.

---

## CIERRE — Carácter normativo

Este documento es la **guía obligatoria de desarrollo** del proyecto. Sus convenciones derivan directamente del DER V1.2, el Diccionario de Datos, la Especificación Funcional MVP V1 y la Arquitectura Backend aprobada: donde esos documentos ya fijan un nombre, una capa, una regla o un mensaje, esta guía lo convierte en norma; donde el equipo necesitaba un estándar operativo (API, Git, frontend, testing), lo define sin alterar arquitectura, modelo de datos ni alcance funcional. Toda contribución al repositorio —backend, frontend, base de datos, pruebas o documentación— debe cumplir estas convenciones; las desviaciones requieren aprobación explícita del líder técnico y quedan documentadas en el Pull Request. El documento se versiona junto con el proyecto: cualquier cambio a una convención se propone por PR sobre `Convenciones.md` y entra en vigor al mergearse.
