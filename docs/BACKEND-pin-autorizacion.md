# Autorización de operaciones sensibles por PIN (M14.1)

> **Estado:** ✅ implementado en el backend. **Fecha:** 2026-07-13.
> **Sustituye** al override por usuario + contraseña de admin descrito en
> `BACKEND-override-autorizacion.md` (ese documento queda como histórico).
> El flujo **asíncrono** (`POST /autorizaciones` + aprobar/rechazar en bandeja) **sigue intacto**.

## Por qué PIN y no la contraseña del admin

El override original hacía que el operador tecleara **las credenciales de acceso** del admin.
Eso las expone (delante del operador, en cada cancelación) y además son credenciales *totales*:
con ellas se inicia sesión, se cambia la configuración, se ven reportes. El PIN es un secreto
**acotado**: solo sirve para autorizar estas 4 operaciones, nunca emite token ni sesión, y se
puede rotar sin tocar la contraseña de acceso.

## Modelo

| Tabla | Para qué |
|---|---|
| `autorizacion_pins` | PIN de cada autorizador, por establecimiento. |
| `autorizacion_intentos` | Bitácora de intentos **fallidos** (auditoría). |
| `autorizaciones` | Sin cambios: la operación concedida, con `metodo = override`. |

`autorizacion_pins` guarda dos derivaciones del PIN, nunca el PIN:

- **`pin_hash`** — bcrypt con salt por fila. Es la verificación real (`Hash::check`, timing-safe).
- **`pin_lookup`** — `hash_hmac('sha256', "{$idEstablecimiento}:{$pin}", config('pos.autorizacion.pin_lookup_key'))`.
  Determinístico, indexado: resuelve la identidad del autorizador en O(1) y **enforza la
  unicidad del PIN dentro del establecimiento** (condición necesaria para que el operador
  teclee *solo* el PIN). Va con clave a propósito: un hash desnudo de 6 dígitos se rompe con
  una tabla de 10⁶ entradas.

Dos propiedades del diseño, ambas cubiertas por `tests/Unit/Autorizaciones/AutorizacionPinLookupTest.php`:

1. **La clave es propia, no `APP_KEY`.** `POS_PIN_LOOKUP_KEY` se genera con
   `php artisan pos:pin-key` y vive solo en el `.env` del despliegue. Compartir el secreto
   con `APP_KEY` sería peligroso en los dos sentidos: la app key acaba filtrándose (imágenes,
   `.env` de ejemplo, historial de git) y arrastraría los PIN consigo, y rotarla —por incidente
   o por higiene— invalidaría los PIN de todos los autorizadores.
2. **El establecimiento entra en el mensaje del HMAC.** Sin él, el mismo PIN produce el mismo
   `pin_lookup` en todos los tenants: quien lea la base de datos vería qué usuarios de bares
   distintos comparten PIN sin romper nada. Al ir dentro, una tabla precomputada solo sirve
   para un establecimiento.

> ⚠️ **`POS_PIN_LOOKUP_KEY` es obligatoria y debe ser estable.** Sin ella la resolución de PIN
> lanza una excepción en voz alta (no se degrada a una clave adivinable). Si rota, todos los
> `pin_lookup` quedan inservibles y cada autorizador debe volver a fijar su PIN — los datos no
> se corrompen: el override responde "PIN inválido" hasta que se refijen. `php artisan pos:pin-key`
> exige `--force` para sobrescribirla justamente por eso.
>
> Trátala como el secreto más sensible del despliegue: quien la tenga junto con lectura de la
> base de datos reconstruye todos los PIN en claro con una tabla de 10⁶ entradas.

Unicidad: `(id_usuario, id_establecimiento)` — un PIN por membresía — y
`(id_establecimiento, pin_lookup)` — PIN único dentro del establecimiento.

## Endpoints de gestión del PIN (self-service)

Solo el propio autorizador fija su PIN. Ni el super admin ni otro admin lo hacen por él: el
secreto de autorización no debe tener copia conocida por terceros.

| Método | Ruta | Notas |
|---|---|---|
| `GET` | `/api/v1/mi-pin` | Estado del PIN. Nunca devuelve el PIN. |
| `PUT` | `/api/v1/mi-pin` | Establece o cambia el PIN. |
| `DELETE` | `/api/v1/mi-pin` | Lo borra (queda "sin configurar"). |

**Quién puede:** solo usuarios con alguno de los 4 permisos sensibles (`ordenes.cancelar_item`,
`ordenes.anular`, `inventario.entrada`, `inventario.ajustar`). A los demás, `PUT` responde `403`.

### `PUT /mi-pin`

```json
{ "pin": "482913", "pin_confirmation": "482913", "password_actual": "••••••••" }
```

- `pin`: exactamente **6 dígitos**, confirmado, y **no trivial** (se rechazan repetidos como
  `111111` y secuencias como `123456` / `654321`).
- `password_actual`: la contraseña de **login** del propio usuario. Sin esto, cualquiera que
  encuentre una sesión abierta podría fijarse un PIN y autorizar overrides a voluntad.
- PIN ya usado por otro usuario del mismo establecimiento →
  `422 { "message": "Ese PIN ya está en uso en este establecimiento. Elige otro." }`.

Respuesta (`GET`, `PUT` y `DELETE` comparten forma):

```json
{ "success": true, "message": "...", "data": { "configurado": true, "actualizado_at": "2026-07-13T18:20:00Z" } }
```

## Override en los 4 endpoints sensibles

| Endpoint | Permiso directo | `tipo` |
|---|---|---|
| `PATCH /ordenes/{id}/items/{itemId}/cancelar` | `ordenes.cancelar_item` | `cancelar_item` |
| `PATCH /ordenes/{id}/anular` | `ordenes.anular` | `anular_orden` |
| `POST /movimientos` (tipo `entrada`) | `inventario.entrada` | `entrada_stock` |
| `POST /movimientos` (tipo `ajuste`) | `inventario.ajustar` | `ajuste_stock` |

Bloque que se agrega al body **solo cuando el usuario NO tiene el permiso directo**:

```json
{
  "motivo": "Cliente se retiró sin consumir",
  "autorizacion_pin": "482913",
  "terminal": "CAJA-1"
}
```

- `motivo`: requerido en override. `autorizacion_pin`: requerido, 6 dígitos.
- `terminal`: opcional, solo para la auditoría de intentos.
- **Ya no existen** `autorizacion_login` ni `autorizacion_password`. Si llegan, se ignoran.

### Flujo

1. Usuario **con** el permiso directo → ejecuta como siempre, sin PIN. *(Retrocompatible.)*
2. Sin el permiso → se exige `motivo` + `autorizacion_pin`.
3. El PIN resuelve al autorizador **dentro del establecimiento activo** (TenantScope). Si no
   resuelve, no verifica, o el autorizador está **inactivo** → `422 { "message": "PIN de
   autorización inválido." }` (idéntico en los tres casos: distinguirlos permitiría enumerar
   PINs) + fila en `autorizacion_intentos` con `resultado = pin_invalido`.
4. El autorizador debe tener el permiso de **esa** operación. Si no →
   `403 { "message": "Ese usuario no puede autorizar esta operación." }` + intento con
   `resultado = sin_permiso`.
5. Se ejecuta la acción y se registra en `autorizaciones`: `estado = aprobada`,
   `metodo = override`, `id_usuario_solicita` = operador, `id_usuario_autoriza` = autorizador,
   `resuelta_at = now()`. La operación queda enlazada por `id_autorizacion`.

Todo el paso 5 va en **una transacción**: si la acción de dominio falla (orden ya no
modificable, stock insuficiente...), se revierte también la autorización — nunca queda una fila
`aprobada` huérfana.

## Seguridad

- **Throttle** 5 intentos / minuto por (operador, IP). El 6º responde `429` con **`Retry-After`**.
  Un override correcto limpia el contador: el operador legítimo no se auto-bloquea.
- **Intentos fallidos** persistidos en `autorizacion_intentos` (operador, tipo, resultado, IP,
  terminal, refs de la operación). Se escriben **fuera** de la transacción: el rastro sobrevive
  al rechazo.
- **El PIN nunca se persiste ni se loguea** en claro: ni en `autorizaciones.datos`, ni en
  `autorizacion_intentos`, ni en `auditoria`, ni en la respuesta.
- **Mismo tenant** en todo: gestión del PIN y resolución del autorizador van por TenantScope.
- Comparación **timing-safe**: si el PIN no resuelve a nadie se verifica igual contra un hash
  señuelo, para que el tiempo de respuesta no delate qué PINs existen.
- El PIN viaja en el body; **HTTPS asumido** en producción.

## Impacto en el frontend

- El modal de autorización pide **solo PIN + motivo** (ya no usuario/correo ni contraseña).
- El admin necesita una pantalla de perfil que consuma `GET/PUT/DELETE /mi-pin`.
- Un `403` en `PUT /mi-pin` significa "este usuario no puede ser autorizador" → no mostrar la
  opción.
- `AutorizacionesPage` sigue siendo el visor de auditoría, con filtro por `estado` / `metodo`.

## Pruebas

`tests/Feature/Autorizaciones/OverrideAutorizacionTest.php` (override por PIN: los 4 endpoints,
PIN inválido, autorizador sin permiso, otro tenant, autorizador inactivo, throttle + Retry-After,
atomicidad, retrocompatibilidad del permiso directo) y `MiPinTest.php` (alta/cambio/borrado,
contraseña actual, PIN trivial, unicidad por establecimiento, gate de autorizador).
