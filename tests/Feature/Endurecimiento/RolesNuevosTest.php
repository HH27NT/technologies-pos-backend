<?php

namespace Tests\Feature\Endurecimiento;

use App\Domain\Usuarios\Services\ProveedorRolesTenant;
use App\Models\Establecimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractuaConTenants;
use Tests\TestCase;

/**
 * Verifica los paquetes de permisos de los roles introducidos en el modelo de 5 roles
 * (ver docs MatrizRoles.md): `gerente` y `mesero`, más el permiso `mesas.ver` que el
 * `operador` gana para poder listar mesas en el POS.
 *
 * Se inspecciona el rol materializado por-tenant (ProveedorRolesTenant), que es la
 * fuente real que consume `useCan` vía `/auth/me`.
 */
class RolesNuevosTest extends TestCase
{
    use InteractuaConTenants, RefreshDatabase;

    private int $idEstablecimiento;

    private ProveedorRolesTenant $proveedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        /** @var Establecimiento $establecimiento */
        ['establecimiento' => $establecimiento] = $this->nuevoTenant('roles');
        $this->idEstablecimiento = $establecimiento->id;
        $this->proveedor = app(ProveedorRolesTenant::class);
    }

    /** @return string[] */
    private function permisosDelRol(string $rol): array
    {
        return $this->proveedor->obtener($rol, $this->idEstablecimiento)
            ->permissions()->pluck('name')->all();
    }

    public function test_operador_gana_mesas_ver(): void
    {
        $this->assertContains('mesas.ver', $this->permisosDelRol('operador'));
        // pero no la gestión.
        $this->assertNotContains('mesas.gestionar', $this->permisosDelRol('operador'));
    }

    public function test_gerente_es_admin_sin_configuracion_ni_auditoria(): void
    {
        $gerente = $this->permisosDelRol('gerente');

        $this->assertContains('usuarios.gestionar', $gerente);
        $this->assertContains('mesas.ver', $gerente);
        $this->assertContains('reportes.ver', $gerente);
        $this->assertContains('autorizaciones.aprobar', $gerente);
        $this->assertContains('inventario.ajustar', $gerente);

        // Las decisiones de dueño quedan fuera del gerente.
        $this->assertNotContains('configuracion.editar', $gerente);
        $this->assertNotContains('auditoria.ver', $gerente);
        $this->assertNotContains('reportes.ver_dashboard', $gerente);
    }

    /** El dashboard de decisiones es exclusivo del dueño: ni el gerente lo tiene. */
    public function test_solo_admin_tiene_ver_dashboard(): void
    {
        $this->assertContains('reportes.ver_dashboard', $this->permisosDelRol('admin'));
        $this->assertNotContains('reportes.ver_dashboard', $this->permisosDelRol('gerente'));
        $this->assertNotContains('reportes.ver_dashboard', $this->permisosDelRol('operador'));
        $this->assertNotContains('reportes.ver_dashboard', $this->permisosDelRol('mesero'));
    }

    public function test_mesero_vende_y_cobra_pero_sin_caja_ni_inventario(): void
    {
        $mesero = $this->permisosDelRol('mesero');

        $this->assertContains('ordenes.crear', $mesero);
        $this->assertContains('ordenes.cobrar', $mesero);
        $this->assertContains('tickets.imprimir', $mesero);
        $this->assertContains('mesas.ver', $mesero);
        $this->assertContains('autorizaciones.solicitar', $mesero);
        $this->assertContains('reportes.ver_limitado', $mesero);

        // Sin caja, sin descuento, sin inventario ni gestión de personal.
        $this->assertNotContains('caja.abrir', $mesero);
        $this->assertNotContains('ordenes.aplicar_descuento', $mesero);
        $this->assertNotContains('inventario.merma', $mesero);
        $this->assertNotContains('usuarios.gestionar', $mesero);
    }
}
