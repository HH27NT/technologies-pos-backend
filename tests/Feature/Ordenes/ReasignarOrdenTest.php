<?php

namespace Tests\Feature\Ordenes;

use App\Domain\Ordenes\Services\AnularOrdenService;
use App\Models\Establecimiento;
use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConstruyeOrdenes;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * M11 · Reasignar mesero (traspaso, mesero fase 1b). Solo ADMIN/GERENTE (`ordenes.reasignar`),
 * sobre órdenes abiertas, hacia un usuario activo del mismo tenant. Auditado.
 */
class ReasignarOrdenTest extends TestCase
{
    use ConstruyeOrdenes, InteractuaConTenants, RefreshDatabase;

    private Establecimiento $establecimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        ['establecimiento' => $this->establecimiento, 'admin' => $this->admin] = $this->nuevoTenant('reasig');
        $this->enContextoDe($this->establecimiento->id);
    }

    /** Orden abierta atribuida al admin (Auth::id() al crearla). */
    private function ordenDelAdmin(): int
    {
        Sanctum::actingAs($this->admin);
        $this->abrirCaja($this->admin);

        return $this->ordenAbiertaConTotal(100)->id;
    }

    public function test_admin_reasigna_orden_abierta_a_otro_usuario(): void
    {
        $id = $this->ordenDelAdmin();
        $mesero = $this->crearUsuarioEnTenant($this->establecimiento->id, 'mesero');

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/ordenes/{$id}/reasignar", ['id_usuario' => $mesero->id])
            ->assertOk()
            ->assertJsonPath('data.usuario.id', $mesero->id)
            ->assertJsonPath('data.id_usuario', $mesero->id);

        $this->assertDatabaseHas('ordenes', ['id' => $id, 'id_usuario' => $mesero->id]);
        $this->assertDatabaseHas('auditoria', [
            'accion' => 'orden.reasignada', 'entidad' => 'ordenes', 'entidad_id' => $id,
        ]);
    }

    public function test_gerente_puede_reasignar(): void
    {
        $id = $this->ordenDelAdmin();
        $gerente = $this->crearUsuarioEnTenant($this->establecimiento->id, 'gerente');
        $mesero = $this->crearUsuarioEnTenant($this->establecimiento->id, 'mesero');

        Sanctum::actingAs($gerente);
        $this->patchJson("/api/v1/ordenes/{$id}/reasignar", ['id_usuario' => $mesero->id])
            ->assertOk()
            ->assertJsonPath('data.usuario.id', $mesero->id);
    }

    public function test_operador_no_puede_reasignar(): void
    {
        $id = $this->ordenDelAdmin();
        $operador = $this->crearUsuarioEnTenant($this->establecimiento->id, 'operador');
        $mesero = $this->crearUsuarioEnTenant($this->establecimiento->id, 'mesero');

        Sanctum::actingAs($operador);
        $this->patchJson("/api/v1/ordenes/{$id}/reasignar", ['id_usuario' => $mesero->id])
            ->assertForbidden();
    }

    public function test_no_reasigna_a_usuario_de_otro_tenant(): void
    {
        $id = $this->ordenDelAdmin();
        ['admin' => $otroAdmin] = $this->nuevoTenant('otro');

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/ordenes/{$id}/reasignar", ['id_usuario' => $otroAdmin->id])
            ->assertStatus(422);
    }

    public function test_no_reasigna_a_usuario_inactivo(): void
    {
        $id = $this->ordenDelAdmin();
        $mesero = $this->crearUsuarioEnTenant($this->establecimiento->id, 'mesero');
        $mesero->update(['activo' => false]);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/ordenes/{$id}/reasignar", ['id_usuario' => $mesero->id])
            ->assertStatus(422);
    }

    public function test_no_reasigna_orden_no_abierta(): void
    {
        $id = $this->ordenDelAdmin();
        $mesero = $this->crearUsuarioEnTenant($this->establecimiento->id, 'mesero');
        app(AnularOrdenService::class)->anular(Orden::findOrFail($id));

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/ordenes/{$id}/reasignar", ['id_usuario' => $mesero->id])
            ->assertStatus(422);
    }
}
