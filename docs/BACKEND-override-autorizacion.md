# Especificación — Override de autorización por contraseña de admin (M14)

> ⛔ **SUSTITUIDO (2026-07-13).** Pedirle al operador las credenciales de acceso del admin las
> expone y son credenciales totales (sirven para iniciar sesión). El override se reimplementó
> con un **PIN de 6 dígitos** por autorizador: ver **`BACKEND-pin-autorizacion.md`**, que es el
> contrato vigente. `autorizacion_login` / `autorizacion_password` ya NO existen en el API.
> Este documento se conserva solo como registro de la decisión.

> **Destino:** repo del backend `bar-pos-saas-api` (Laravel 12 + Sanctum). Este documento
> vive en el frontend solo como contrato acordado. **Fecha:** 2026-07-10.
>
> **Objetivo de producto.** Que un operador ejecute una operación sensible **en el momento**
> tecleando la **contraseña de un administrador** (patrón "override de gerente"), sin esperar
> una aprobación asíncrona ni requerir que el admin esté en su propia sesión. Cada override
> queda **registrado** en la tabla `autorizaciones`.

## Por qué se necesita un cambio de backend

Sondeo contra el backend real (2026-07-10) confirmó que **hoy no existe** el mecanismo:

- `PATCH /ordenes/{id}/items/{itemId}/cancelar` y `PATCH /ordenes/{id}/anular` responden **403**
  al operador **sin importar** qué campo de contraseña se envíe (`password`, `pin`,
  `autorizacion_password`, etc.). La autorización es a nivel de permiso (spatie), no de payload.
- No hay endpoint de verificación de contraseña (probados varios → 404).
- `GET /configuracion` no expone ninguna "clave de autorización".
- El único mecanismo de M14 es **asíncrono**: `POST /autorizaciones` (crea `pendiente`) +
  `PATCH /autorizaciones/{id}/aprobar|rechazar` (admin, desde su sesión).

La tabla `autorizaciones` ya guarda lo que se quiere reportar: `id_usuario_solicita`,
`id_usuario_autoriza`, `motivo`, `estado`, `resuelta_at`, `datos`. El override debe **reusar
esa tabla** (que es la "tabla aparte" del requerimiento).

## Diseño recomendado

Aumentar los **endpoints de la acción sensible** con un bloque opcional de autorización. Si el
usuario autenticado **ya tiene el permiso directo**, el endpoint se comporta **igual que hoy**
(no se pide contraseña). Si **no** lo tiene, se exige el bloque de override.

### Endpoints afectados

| Endpoint | Permiso directo | Tipo de autorización |
|---|---|---|
| `PATCH /ordenes/{id}/items/{itemId}/cancelar` | `ordenes.cancelar_item` | `cancelar_item` |
| `PATCH /ordenes/{id}/anular` | `ordenes.anular` | `anular_orden` |
| `POST /movimientos` (tipo `entrada`) | `inventario.entrada` | `entrada_stock` |
| `POST /movimientos` (tipo `ajuste`) | `inventario.ajustar` | `ajuste_stock` |

### Request (cuando el usuario NO tiene el permiso directo)

Se agregan al body actual del endpoint:

```json
{
  "motivo": "Cliente se retiró sin consumir",
  "autorizacion_login": "admin.demo",
  "autorizacion_password": "••••••••"
}
```

- `autorizacion_login`: usuario **o** correo del admin autorizador.
- `autorizacion_password`: su contraseña (se verifica con `Hash::check`, timing-safe).
- `motivo`: requerido cuando se usa override.

### Lógica del backend

1. Si `auth()->user()` **tiene** el permiso directo del endpoint → ejecutar como hoy
   (ignorar/omitir el bloque de override). Retrocompatible.
2. Si **no** lo tiene → exigir `motivo` + `autorizacion_login` + `autorizacion_password`.
3. Resolver al **autorizador**: usuario **activo** del **mismo establecimiento** (tenant) cuyo
   `username` o `email` = `autorizacion_login`. Si no existe o `Hash::check` falla →
   **422** `{ "message": "Credenciales de autorización inválidas." }` (genérico, sin revelar
   si el usuario existe).
4. Verificar que el autorizador **tiene el permiso** correspondiente (`ordenes.anular`,
   `ordenes.cancelar_item`, `inventario.entrada`, `inventario.ajustar`). Si no →
   **403** `{ "message": "Ese usuario no puede autorizar esta operación." }`.
5. **Ejecutar** la acción (misma lógica que la ruta directa).
6. **Registrar** en `autorizaciones`:
   - `tipo`, `entidad`, `entidad_id`, `datos` (refs de la operación),
   - `motivo`,
   - `estado = "aprobada"`,
   - `id_usuario_solicita = auth()->id()` (el operador),
   - `id_usuario_autoriza = <id del autorizador>`,
   - `resuelta_at = now()`,
   - `metodo = "override"` *(columna nueva recomendada; ver Migración)*.
7. Responder con el envelope estándar y el recurso ejecutado (orden/ítem/movimiento). Opcional:
   incluir `autorizacion_id` en `data`.

### Migración recomendada

Agregar a `autorizaciones` una columna para distinguir el origen:

```php
$table->enum('metodo', ['asincrono', 'override'])->default('asincrono');
```

Así la bandeja/reporte puede separar "aprobadas desde bandeja" de "override con contraseña".

### Seguridad (obligatorio)

- **Throttling** del override (p. ej. `throttle:5,1` por usuario/IP) para frenar fuerza bruta
  contra la contraseña del admin.
- **Nunca** emitir token/sesión para el autorizador: es una verificación de un solo uso.
- Enforzar **mismo tenant**: un admin de otro establecimiento no puede autorizar.
- Recomendado: registrar también los **intentos fallidos** (auditoría M15) para trazabilidad.
- La contraseña viaja en el body sobre HTTPS; no loguear el valor en claro.

### Permisos

- No hace falta un permiso nuevo. El "gate" real es que el **autorizador** tenga el permiso.
- El frontend seguirá usando `autorizaciones.solicitar` como bandera para **mostrar** la opción
  de override al operador.

## Alternativa (endpoint dedicado)

Si se prefiere un solo punto de entrada en vez de tocar 3 endpoints:

```
POST /autorizaciones/override
{ "tipo", "motivo", "autorizacion_login", "autorizacion_password", ...refs }
```

Misma lógica (verifica → ejecuta → registra en `autorizaciones` con `estado="aprobada"`,
`metodo="override"`). Ventaja: un solo code path para los 4 tipos. Desventaja: duplica el
"router" de qué acción ejecutar según `tipo`.

## Impacto en el frontend (cuando el endpoint exista)

- El `AuthorizationRequestDialog` cambia de "enviar solicitud" a **override**: pide
  `usuario admin` + `contraseña` + `motivo` y llama al endpoint aumentado; la acción se ejecuta
  al instante (sin estado `pendiente`).
- `AutorizacionesPage` se mantiene como **visor de auditoría** (lista de overrides + asíncronas),
  con filtro por `estado`/`metodo`.
- La versión **asíncrona** ya implementada (solicitar → aprobar en bandeja) puede conservarse
  como respaldo (cuando no se conoce la contraseña del admin) o retirarse. **Decisión pendiente.**

## Estado

- ⛔ **Bloqueado en backend.** La UI del override no se puede cablear hasta que exista el endpoint.
- La Fase 8 quedó implementada en su **versión asíncrona** (funcional y verificada E2E); el
  cambio a override se hará cuando el backend exponga este contrato.
