<?php

namespace Tests\Unit\Establecimientos;

use App\Domain\Establecimientos\Services\ActualizarConfiguracionService;
use App\Domain\Establecimientos\Services\CrearEstablecimientoService;
use App\Models\ConfiguracionEstablecimiento;
use App\Support\Exceptions\ConfiguracionInvalidaException;
use App\Support\Tenant\TenantContext;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActualizarConfiguracionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConfiguracionEstablecimiento $configuracion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermisosSeeder::class);

        $resultado = app(CrearEstablecimientoService::class)->crear([
            'nombre' => 'Bar Config',
            'admin' => ['nombre' => 'A', 'email' => 'a@config.test', 'password' => 'password123'],
        ]);

        $est = $resultado['establecimiento'];
        app(TenantContext::class)->set($est->id);
        $this->configuracion = ConfiguracionEstablecimiento::where('id_establecimiento', $est->id)->firstOrFail();
    }

    public function test_actualiza_los_datos_de_ticket_y_audita(): void
    {
        app(ActualizarConfiguracionService::class)->actualizar($this->configuracion, [
            'nombre_comercial' => 'La Cantina',
            'telefono_ticket' => '5551234567',
            'impresion_automatica' => true,
        ]);

        $this->assertDatabaseHas('configuracion_establecimiento', [
            'id' => $this->configuracion->id,
            'nombre_comercial' => 'La Cantina',
            'telefono_ticket' => '5551234567',
            'impresion_automatica' => true,
        ]);

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'configuracion.actualizada',
            'entidad' => 'configuracion_establecimiento',
            'entidad_id' => $this->configuracion->id,
        ]);
    }

    public function test_activa_impuesto_con_tasa_valida(): void
    {
        app(ActualizarConfiguracionService::class)->actualizar($this->configuracion, [
            'aplica_impuesto' => true,
            'tasa_impuesto' => 16,
        ]);

        $this->assertDatabaseHas('configuracion_establecimiento', [
            'id' => $this->configuracion->id,
            'aplica_impuesto' => true,
            'tasa_impuesto' => 16,
        ]);
    }

    public function test_impuesto_activo_sin_tasa_es_rechazado(): void
    {
        $this->expectException(ConfiguracionInvalidaException::class);

        app(ActualizarConfiguracionService::class)->actualizar($this->configuracion, [
            'aplica_impuesto' => true,
        ]);
    }

    public function test_impuesto_activo_con_tasa_cero_es_rechazado(): void
    {
        $this->expectException(ConfiguracionInvalidaException::class);

        app(ActualizarConfiguracionService::class)->actualizar($this->configuracion, [
            'aplica_impuesto' => true,
            'tasa_impuesto' => 0,
        ]);
    }

    public function test_un_fallo_de_estado_no_persiste_cambios_ni_auditoria(): void
    {
        try {
            app(ActualizarConfiguracionService::class)->actualizar($this->configuracion, [
                'nombre_comercial' => 'No Debe Guardarse',
                'aplica_impuesto' => true,
            ]);
        } catch (ConfiguracionInvalidaException) {
            // Esperado: la validación de estado corta antes de tocar la BD.
        }

        $this->assertDatabaseMissing('configuracion_establecimiento', [
            'id' => $this->configuracion->id,
            'nombre_comercial' => 'No Debe Guardarse',
        ]);
        $this->assertDatabaseMissing('auditoria', ['accion' => 'configuracion.actualizada']);
    }
}
