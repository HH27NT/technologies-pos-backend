<?php

namespace Tests\Feature\Endurecimiento;

use App\Models\Establecimiento;
use App\Models\Insumo;
use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * S12 · Matriz de permisos completa (Fase 7 / §541): cada rol × cada acción. Se verifican
 * los LÍMITES DE SEGURIDAD: el OPERADOR es rechazado (403) en las operaciones de solo-admin
 * y en las 4 operaciones bloqueadas 🔐 (que solo puede SOLICITAR, S9), pero opera lo suyo;
 * el ADMIN es rechazado en las de plataforma; el SUPER_ADMIN pasa por Gate::before.
 */
class MatrizPermisosTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    private Usuario $operador;

    private Orden $orden;

    private Insumo $insumo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('matriz');
        $this->operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');

        $this->enContextoDe($this->establecimiento->id);
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);

        $this->insumo = Insumo::factory()->conStock(50)->create();
        $this->orden = $this->ordenAbiertaConTotal(100);
    }

    /** El operador es rechazado en las operaciones de solo-admin. */
    public function test_operador_no_accede_a_operaciones_de_admin(): void
    {
        Sanctum::actingAs($this->operador);

        // Reportes de gestión, dashboard y auditoría (solo admin).
        $this->getJson('/api/v1/reportes/inventario')->assertForbidden();
        $this->getJson('/api/v1/reportes/margen')->assertForbidden();
        $this->getJson('/api/v1/reportes/cancelaciones')->assertForbidden();
        $this->getJson('/api/v1/reportes/dashboard')->assertForbidden();
        $this->getJson('/api/v1/auditoria')->assertForbidden();

        // Gestión de catálogo y de personal (solo admin).
        $this->postJson('/api/v1/categorias', ['nombre' => 'Bebidas'])->assertForbidden();
        $this->postJson('/api/v1/usuarios', [
            'nombre' => 'Otro', 'email' => 'otro@matriz.test', 'password' => 'password123', 'rol' => 'operador',
        ])->assertForbidden();
    }

    /**
     * El operador NO puede ejecutar las 4 operaciones 🔐 por su cuenta: carece del permiso
     * directo y sin el bloque de override (contraseña de admin, M14) el endpoint responde
     * 422 (falta el bloque), no las materializa.
     */
    public function test_operador_no_tiene_permiso_directo_de_operaciones_bloqueadas(): void
    {
        Sanctum::actingAs($this->operador);
        $item = $this->orden->detalles()->first();

        $this->patchJson("/api/v1/ordenes/{$this->orden->id}/anular")->assertStatus(422);
        $this->patchJson("/api/v1/ordenes/{$this->orden->id}/items/{$item->id}/cancelar")->assertStatus(422);

        // Entrada y ajuste de stock: directos solo para admin (el operador necesita override).
        $this->postJson('/api/v1/movimientos', [
            'id_insumo' => $this->insumo->id, 'tipo' => 'entrada', 'cantidad' => 10, 'costo_unitario' => 5,
        ])->assertStatus(422);
        $this->postJson('/api/v1/movimientos', [
            'id_insumo' => $this->insumo->id, 'tipo' => 'ajuste', 'cantidad' => 3, 'motivo' => 'Conteo',
        ])->assertStatus(422);
    }

    /** El operador SÍ opera lo suyo (caja, órdenes, reportes limitados, merma). */
    public function test_operador_accede_a_lo_suyo(): void
    {
        Sanctum::actingAs($this->operador);

        $this->getJson('/api/v1/reportes/ventas?preset=hoy')->assertOk();
        $this->getJson('/api/v1/caja/actual')->assertOk();
        $this->getJson('/api/v1/ordenes')->assertOk();
        $this->postJson('/api/v1/movimientos', [
            'id_insumo' => $this->insumo->id, 'tipo' => 'merma', 'cantidad' => 1, 'motivo' => 'Derrame',
        ])->assertCreated();
    }

    /** El admin del establecimiento no accede a las operaciones de plataforma. */
    public function test_admin_no_accede_a_plataforma(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/establecimientos')->assertForbidden();
        $this->getJson('/api/v1/auditoria/global')->assertForbidden();
    }

    /** El admin sí opera todo su establecimiento. */
    public function test_admin_accede_a_lo_suyo(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/auditoria')->assertOk();
        $this->getJson('/api/v1/reportes/inventario')->assertOk();
        $this->postJson('/api/v1/categorias', ['nombre' => 'Bebidas'])->assertCreated();
    }

    /** El super_admin pasa las autorizaciones de plataforma (Gate::before). */
    public function test_super_admin_accede_a_plataforma_y_global(): void
    {
        $super = Usuario::withoutGlobalScopes()->where('email', 'super@pos.local')->firstOrFail();
        Sanctum::actingAs($super);

        $this->getJson('/api/v1/establecimientos')->assertOk();
        $this->getJson('/api/v1/auditoria/global')->assertOk();
    }
}
