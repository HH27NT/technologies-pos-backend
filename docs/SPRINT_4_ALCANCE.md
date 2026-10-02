# SPRINT_4_ALCANCE.md — Catálogo de venta y posiciones

**Proyecto:** SaaS POS Multi-Tenant (Laravel 12 · PostgreSQL 15+ · Sanctum · spatie/laravel-permission)
**Fecha:** 2026-06-16
**Base:** `RoadmapImplementacion.md` (Sprint 4, §4 Pruebas, §5 DoD). Arranca sobre la **capa 1 ya completa** (M01–M04 + auditoría base + M03 Configuración).

> **Contexto.** Cerrada la capa 1, el roadmap avanza a la **capa 2 (catálogos que consume la venta)**. El Sprint 4 entrega el CRUD de las entidades estáticas que una orden referencia directamente: categorías, productos, mesas e impresoras.

---

## 1. Módulos entregados

| Módulo | Endpoints (cadena `auth:sanctum → resolve.tenant → tenant.activo`) |
|---|---|
| **M05 · Categorías** | `GET/POST /categorias`, `GET/PUT /categorias/{id}`, `PATCH /categorias/{id}/activar` |
| **M06 · Productos** | `GET/POST /productos`, `GET/PUT /productos/{id}`, `PATCH /productos/{id}/activar` (filtro `?id_categoria=`) |
| **M09 · Mesas** | `GET/POST /mesas`, `GET/PUT /mesas/{id}`, `PATCH /mesas/{id}/activar` |
| **M13 · Impresoras** | `GET/POST /impresoras`, `GET/PUT /impresoras/{id}`, `PATCH /impresoras/{id}/activar` |

**Servicios** (en `app/Domain/Catalogo/Services`, transaccionales y auditados §15): `GuardarCategoriaService`, `GuardarProductoService`, `GuardarMesaService`, `GuardarImpresoraService` — cada uno con `crear` / `actualizar` / `cambiarEstado`.
**Policies** (solo ADMIN, permiso `{recurso}.gestionar`): `CategoriaProductoPolicy`, `ProductoPolicy`, `MesaPolicy`, `ImpresoraPolicy`.
**Resources:** `CategoriaProductoResource`, `ProductoResource`, `MesaResource`, `ImpresoraResource`.
**Form Requests:** `GuardarCategoriaRequest`, `GuardarProductoRequest`, `GuardarMesaRequest`, `GuardarImpresoraRequest`.

## 2. Decisiones y contratos

- **`activar` alterna un booleano, no el soft delete.** `PATCH .../activar` cambia `activo`/`activa` (en productos, `disponible`), mismo patrón que Establecimiento/Usuario. El `SoftDeletes` de los modelos queda disponible para una baja lógica futura; no se expone un endpoint `DELETE` (el roadmap lista solo GET/POST/PUT/PATCH).
- **Producto `controla_inventario = true` sin receta:** el catálogo **permite** crearlo; el descuento de inventario al cobrar dependerá de que exista la receta (M07, Sprint 5). El catálogo no bloquea ni rompe (contrato del Sprint 4).
- **Desactivar categoría con productos:** **advierte, no bloquea** (mensaje en la respuesta).
- **Estado de mesa (libre/ocupada):** es **derivado** de la orden abierta (fuente de verdad M11), no una columna. Hasta que exista Órdenes, sin orden abierta ⇒ `libre`.
- **Unicidades por tenant:** número de mesa único por establecimiento (índice parcial + Form Request); la categoría de un producto debe pertenecer al mismo tenant.
- **Auditoría:** alta/edición/cambio de estado de las 4 entidades se registran en `auditoria` dentro de la transacción. La auditoría CRUD por **Observer** de maestros se consolida en el Sprint 12 (roadmap); aquí se hace vía servicio.

## 3. Pruebas (DoD §2/§3/§4)

- **Feature** (20): CRUD de las 4 entidades; policies (operador → 403); validaciones (nombre/categoría obligatorios, precio ≥ 0, tipo de impresora en ENUM, número de mesa único por tenant); advertencia al desactivar categoría con productos; alternancia de disponibilidad/estado.
- **Integration** (2): aislamiento multi-tenant (un admin no ve ni accede al catálogo de otro; id ajeno → 404) y auditoría de creación de catálogo.
- **Estado:** suite completa **67/67 verde** en **SQLite** y en **PostgreSQL**; **Pint** limpio.

## 4. Fuera de alcance (siguientes sprints)

- **M07 Recetas** y **M08 Inventario** (insumos/proveedores/unidades/movimientos): **Sprint 5**.
- Generación de comanda/ticket de las impresoras: capa 5 (Sprint 10).
- Estado real de mesa ocupada: se conecta al llegar M11 (Sprint 7).

## 5. Deuda heredada (sin cambios, gestionar en sprints posteriores)

Factories restantes, decisión formal de RLS, divergencia de timestamps (R4), unificación del patrón de auditoría (Observer en Sprint 12).
