# Reestructuración del backend — Suscripciones/Planes + Mapa de mesas y Reservaciones

> Guía de implementación para **bar-pos-saas-api** (Laravel 12). Redactada contra la
> estructura real del repo. Son **dos entregables independientes**:
>
> - **A · Suscripciones y planes** — habilita el panel del super admin (saber qué
>   clientes hay, su plan y su vencimiento; notificar/desactivar). Prioridad media.
> - **B · Mapa de mesas + Reservaciones** — la Fase 2 (micrositio público del bar).
>   Prioridad futura.
>
> El frontend ya está diseñado para ambos (ver el mockup del panel y el prototipo
> Konva del mapa). Este doc es lo que falta del lado del API.

## Convenciones del repo (para respetarlas)

- Migraciones: `database/migrations/2026_06_13_0500NN_create_<tabla>_table.php` (secuencia manual). Tablas en **plural español**.
- FK de tenant: **`id_establecimiento`** (no `establecimiento_id`).
- Modelo de tenant: `use Auditable, BelongsToTenant, SoftDeletes;` (`BelongsToTenant` auto-rellena y filtra por tenant). Los **catálogos globales** (sin tenant) NO usan el trait — se ven completos cuando no hay contexto (patrón `CatalogosGlobalesSeeder`).
- Lógica en `app/Domain/<Dominio>/Services/`; controllers delgados que extienden `ApiController` y responden con `ApiResponse::exito/creado/coleccion`.
- Permisos: strings `'<recurso>.<accion>'` en `database/seeders/RolesPermisosSeeder.php` (constante `PERMISOS` + arrays por rol). Policies auto-descubiertas.
- Rutas: `routes/api.php`, prefijo `v1`, explícitas, bajo `auth:sanctum → resolve.tenant → tenant.activo`.

---

# A · Suscripciones y planes

## A.1 Tabla `planes` (catálogo global, sin tenant scope)

Sigue el patrón de catálogos globales (como unidades globales). **No** lleva `id_establecimiento` ni `BelongsToTenant`.

```
2026_..._create_planes_table.php
  id
  clave           string, unique      // 'basico' | 'pro' | 'premium'
  nombre          string
  precio_mensual  decimal(10,2)
  caracteristicas json nullable        // ['micrositio','mapa_mesas','reservaciones']
  activo          boolean default true
  timestamps
```

`caracteristicas` es lo que permite gatear la Fase 2 sin hardcodear: cuando un bar
entre a reservaciones, el backend revisa si su plan la incluye (mismo espíritu que
los permisos). Siembra los planes en un seeder nuevo `PlanesSeeder` (registrado en
`DatabaseSeeder`, junto a `CatalogosGlobalesSeeder`).

## A.2 Tabla `suscripciones` (ligada al tenant)

Una suscripción vigente por establecimiento.

```
2026_..._create_suscripciones_table.php
  id
  id_establecimiento  foreignId constrained('establecimientos')
  id_plan             foreignId constrained('planes')
  estado              string   // 'prueba' | 'activa' | 'por_vencer' | 'vencida' | 'cancelada'
  inicia_at           date
  vence_at            date
  ultimo_pago_at      date nullable
  monto_mensual       decimal(10,2)   // congelado al contratar (regla #6: no recalcular en front)
  timestamps
  softDeletes
```

> `estado` puede derivarse de `vence_at` vs. hoy, pero guardarlo explícito permite
> pausas/cancelaciones manuales del super admin. Un job diario podría recalcular
> `por_vencer`/`vencida`.

Modelo `Suscripcion` con `BelongsToTenant` **no** aplica igual aquí: el super admin
las gestiona cruzando tenants (como Establecimiento). Trátala como el módulo M02
(super_admin, sin scope automático) — exponla vía policy `establecimientos.*`/nueva
`suscripciones.gestionar`, y en el `EstablecimientoResource` agrega
`hasOne(Suscripcion)` para que el frontend muestre plan+vencimiento por bar.

## A.3 Enganche en el ciclo de vida

1. **Al crear el establecimiento** — en `CrearEstablecimientoService`: crea también
   una suscripción inicial (ej. plan `prueba`, `vence_at` = hoy + 14 días). Así todo
   bar nace con estado de cobro conocido.
2. **Vigencia** — en el middleware `EnsureTenantActivo` (`app/Http/Middleware/`)
   puedes añadir, opcionalmente, un chequeo de suscripción vencida para degradar o
   bloquear. **Cuidado**: no bloquees el login ni el pago de la propia suscripción.
   Recomendado al inicio: NO bloquear automático; solo mostrar el estado al super
   admin y que él decida desactivar (la acción `activar` ya existe).

## A.4 Endpoints (módulo Plataforma, super_admin)

Bajo el grupo autenticado, siguiendo el estilo de `EstablecimientoController`:

```
GET   v1/plataforma/resumen                    // tiles del dashboard (ver A.5)
GET   v1/plataforma/suscripciones              // lista + ?estado=vencida
GET   v1/plataforma/suscripciones/{id}
PUT   v1/plataforma/suscripciones/{id}          // cambiar plan / fechas
POST  v1/plataforma/suscripciones/{id}/notificar  // avisar al dueño (email ya existe en establecimiento)
```

Permisos nuevos en `RolesPermisosSeeder`: `planes.gestionar`, `suscripciones.gestionar`
(añádelos SOLO a `PERMISOS_SUPER_ADMIN`).

## A.5 `GET /plataforma/resumen` — resuelve la deuda del frontend

El panel `PanelPlataforma.tsx` hoy calcula activos/inactivos/nuevos sobre la página
cargada (aproximado). Este endpoint da los agregados exactos:

```json
{
  "establecimientos": { "total": 12, "activos": 10, "inactivos": 2, "nuevos_30d": 3 },
  "suscripciones":    { "vencidas": 2, "por_vencer_7d": 3, "al_corriente": 7, "mrr": 24600 }
}
```

Cuando exista, el frontend cambia de `useEstablecimientos({per_page:100})` a
`usePlataformaResumen()` y quita el cálculo en cliente.

---

# B · Mapa de mesas + Reservaciones (Fase 2)

Basado en el prototipo Konva. El "mapa" es un JSON de posiciones; el estado
(libre/reservada/ocupada) es dinámico y **no** se guarda en el layout.

## B.1 Layout del mapa

El prototipo guarda por establecimiento:

```json
{
  "canvas": { "w": 900, "h": 600 },
  "decor":  [{ "id":"barra", "type":"rect", "x":40, "y":40, "w":300, "h":60, "label":"🍹 BARRA" }],
  "tables": [{ "id":"t1", "type":"round", "x":180, "y":200, "seats":4, "name":"Mesa 1", "rot":0 }]
}
```

Recomendación **híbrida** (respeta que `mesas` ya es una entidad real con capacidad,
FK, auditoría — no la disuelvas en un JSON):

1. **Migración `add_layout_to_mesas`** — coordenadas por mesa:
   ```
   pos_x     integer nullable
   pos_y     integer nullable
   forma     string  nullable   // 'round' | 'square' | 'rect'  (mapea al `type` del prototipo)
   ancho     integer nullable   // solo rect
   alto      integer nullable
   rotacion  smallint default 0
   ```
   `seats` del prototipo = la `capacidad` que ya existe. `name` = `nombre`. El `id`
   del prototipo (`t1`) mapea al `id` real de la mesa.

2. **Tabla `mapa_mesas`** (1 por establecimiento) para lo que NO es una mesa: el
   `canvas` y los `decor` (barra, entrada, baños):
   ```
   2026_..._create_mapa_mesas_table.php
     id
     id_establecimiento  foreignId constrained  (unique)
     canvas              json     // {w,h}
     decor               json     // [{id,type,x,y,w,h,label}]
     timestamps
   ```

Endpoints (tenant, permiso nuevo `mesas.layout` o reusar `mesas.gestionar`):
```
GET  v1/mesas/mapa            // arma {canvas, decor, tables[]} para el editor/visor
PUT  v1/mesas/mapa            // guarda posiciones (bulk) + decor
```

## B.2 Módulo `reservaciones` (nuevo, patrón M09 completo)

Replica el patrón de Mesa (migración + modelo + service + request + resource + policy
+ controller + rutas + permiso). Tabla:

```
2026_..._create_reservaciones_table.php
  id
  id_establecimiento  foreignId constrained('establecimientos')
  id_mesa             foreignId nullable constrained('mesas')
  nombre_cliente      string
  telefono            string nullable
  email               string nullable
  personas            integer
  fecha               date
  bloque              string        // '20:00' — bloque de horario (ver nota de producto)
  duracion_min        integer default 90
  estado              string        // 'pendiente' | 'confirmada' | 'cancelada' | 'cumplida' | 'no_show'
  origen              string        // 'publico' | 'staff'
  notas               text nullable
  timestamps
  softDeletes
```

- Modelo `Reservacion` con `use Auditable, BelongsToTenant, SoftDeletes;`.
- Validar en el Service que `personas <= mesa.capacidad` (el prototipo ya lo hace en UI; el backend es la autoridad, regla #6).
- Permiso `reservaciones.gestionar` en el seeder (a `PERMISOS_ADMIN` y `PERMISOS_OPERADOR`).

**Disponibilidad por horario** (la distinción clave): el estado que ve el cliente NO
es la ocupación en vivo del POS (mesa con orden abierta), sino si hay una reservación
para ese `fecha`+`bloque`. Son dos consultas distintas:
- Pública/reservas: `reservaciones WHERE fecha=? AND bloque=? AND estado IN (pendiente,confirmada)`.
- Interna/vivo: la derivación que ya existe (`mesa.ordenAbierta()`).

## B.3 Sitio público (endpoints SIN auth)

Hoy **todo** `api.php` vive tras `auth:sanctum`. El micrositio de reservas necesita
un grupo público nuevo, resuelto por **slug** del establecimiento (no por sesión):

```
// Fuera de auth:sanctum. Resolver tenant por slug, no por token.
GET  v1/publico/{slug}/mapa?fecha=YYYY-MM-DD&bloque=20:00   // mapa + disponibilidad
POST v1/publico/{slug}/reservaciones                         // crear reservación (origen='publico')
```

Requiere:
- Un **`slug`** único por establecimiento (migración `add_slug_to_establecimientos` o
  en la nueva tabla `sitios_publicos` con branding: `color_primario`, `color_secundario`,
  `logo_url`, `portada_url`, `descripcion`, `horario`).
- Un resolvedor de tenant por slug para estas rutas (variante de `ResolveTenant` que
  fija el contexto desde el slug de la URL en vez del usuario).
- Rate limiting y captcha en el POST público (anti-spam de reservas).
- Gate por plan: el slug solo responde si la suscripción incluye `micrositio`/`reservaciones`.

---

## Orden sugerido de implementación

1. **A.1–A.5** (planes + suscripciones + `/plataforma/resumen`) → enciende el panel del super admin.
2. **B.1** (layout en mesas) → editor de mapa interno.
3. **B.2** (reservaciones internas, staff) → el bar registra reservas.
4. **B.3** (sitio público + branding) → el micrositio de cara al cliente.

Cada bloque es entregable por sí solo; el frontend puede consumirlos conforme aparezcan.
