<?php

namespace Tests\Integration\Auditoria;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * Pruebas de integración de la auditoría (§15 / DoD Sprint 1 §4):
 * - Escritura vía eventos de negocio (subscriber síncrono RegistrarAuditoria).
 * - Persistencia DENTRO de la transacción (si se revierte, la auditoría también).
 * - Impersonación del super_admin marcada y auditada (P17).
 */
class RegistrarAuditoriaTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_audita_la_creacion_de_establecimiento_via_evento(): void
    {
        ['establecimiento' => $est] = $this->nuevoTenant('aud');

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'establecimiento.creado',
            'entidad' => 'establecimientos',
            'entidad_id' => $est->id,
        ]);
    }

    public function test_audita_la_creacion_de_usuario_via_evento(): void
    {
        ['establecimiento' => $est] = $this->nuevoTenant('usr');

        $usuario = $this->crearUsuarioEnTenant($est->id, 'operador');

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'usuario.creado',
            'entidad' => 'usuarios',
            'entidad_id' => $usuario->id,
        ]);
    }

    public function test_la_auditoria_se_revierte_con_la_transaccion(): void
    {
        $servicio = app(RegistrarAuditoriaService::class);

        try {
            DB::transaction(function () use ($servicio) {
                $servicio->registrar(accion: 'prueba.rollback', entidad: 'usuarios', entidadId: 999);
                throw new RuntimeException('forzar rollback');
            });
        } catch (RuntimeException) {
            // Esperado: la transacción se revierte.
        }

        // La acción se revirtió → su auditoría NO debe persistir (consistencia §15).
        $this->assertDatabaseMissing('auditoria', ['accion' => 'prueba.rollback']);

        // Caso positivo: fuera de una transacción revertida, sí persiste.
        $servicio->registrar(accion: 'prueba.commit', entidad: 'usuarios', entidadId: 999);
        $this->assertDatabaseHas('auditoria', ['accion' => 'prueba.commit']);
    }

    public function test_impersonacion_de_super_admin_queda_auditada(): void
    {
        ['establecimiento' => $est] = $this->nuevoTenant('imp');
        $superAdmin = $this->superAdmin();

        Sanctum::actingAs($superAdmin);

        $this->withHeader('X-Establecimiento-Id', (string) $est->id)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'soporte.impersonacion',
            'entidad' => 'establecimientos',
            'entidad_id' => $est->id,
            'id_usuario' => $superAdmin->id,
            'id_establecimiento' => $est->id,
        ]);
    }
}
