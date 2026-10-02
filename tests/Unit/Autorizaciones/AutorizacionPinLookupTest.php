<?php

namespace Tests\Unit\Autorizaciones;

use App\Models\AutorizacionPin;
use App\Support\Tenant\TenantContext;
use RuntimeException;
use Tests\TestCase;

/**
 * M14.1 · Propiedades de seguridad del índice HMAC del PIN (AutorizacionPin::lookup()).
 *
 * `pin_lookup` cubre un espacio de solo 10^6 combinaciones: quien conozca la clave del
 * HMAC y tenga lectura de la BD reconstruye todos los PIN en claro con una tabla
 * precomputada. Estas pruebas fijan las tres garantías que hacen viable el diseño:
 *
 *   1. La clave es propia y NO es APP_KEY (que se filtra con facilidad y debe poder rotar).
 *   2. El establecimiento entra en el mensaje, así que una tabla precomputada solo sirve
 *      para un tenant y dos bares no revelan que comparten PIN.
 *   3. Sin clave configurada falla en voz alta, no se degrada a un hash desnudo.
 */
class AutorizacionPinLookupTest extends TestCase
{
    private const PIN = '482913';

    /** Clave arbitraria distinta de la del entorno, para probar la sensibilidad al secreto. */
    private function otraClave(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    public function test_el_indice_no_depende_de_la_app_key(): void
    {
        $antes = AutorizacionPin::lookup(self::PIN, 7);

        config(['app.key' => $this->otraClave()]);

        $this->assertSame(
            $antes,
            AutorizacionPin::lookup(self::PIN, 7),
            'Rotar APP_KEY no debe invalidar los PIN: el índice usa su propia clave.'
        );
    }

    public function test_el_indice_si_depende_de_la_clave_dedicada(): void
    {
        $antes = AutorizacionPin::lookup(self::PIN, 7);

        config(['pos.autorizacion.pin_lookup_key' => $this->otraClave()]);

        $this->assertNotSame(
            $antes,
            AutorizacionPin::lookup(self::PIN, 7),
            'Si el índice no cambia con la clave, no está siendo aplicada al HMAC.'
        );
    }

    public function test_el_mismo_pin_produce_indices_distintos_en_establecimientos_distintos(): void
    {
        $this->assertNotSame(
            AutorizacionPin::lookup(self::PIN, 1),
            AutorizacionPin::lookup(self::PIN, 2),
            'Dos bares con el mismo PIN no deben compartir pin_lookup: la coincidencia sería '
            .'visible para cualquiera que lea la base de datos.'
        );
    }

    public function test_toma_el_establecimiento_del_contexto_cuando_no_se_pasa(): void
    {
        app(TenantContext::class)->set(42);

        $this->assertSame(
            AutorizacionPin::lookup(self::PIN, 42),
            AutorizacionPin::lookup(self::PIN),
        );
    }

    public function test_sin_contexto_no_puede_coincidir_con_una_fila_almacenada(): void
    {
        app(TenantContext::class)->olvidar();

        // Toda fila persistida lleva establecimiento; el índice "sin tenant" es otro valor,
        // de modo que la resolución falla cerrada en vez de abrirse a cualquier tenant.
        $sinContexto = AutorizacionPin::lookup(self::PIN);

        foreach ([1, 2, 3] as $idEstablecimiento) {
            $this->assertNotSame($sinContexto, AutorizacionPin::lookup(self::PIN, $idEstablecimiento));
        }
    }

    public function test_sin_clave_configurada_falla_en_voz_alta(): void
    {
        config(['pos.autorizacion.pin_lookup_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POS_PIN_LOOKUP_KEY');

        AutorizacionPin::lookup(self::PIN, 1);
    }

    public function test_es_determinista_y_no_colisiona_entre_pines(): void
    {
        $this->assertSame(
            AutorizacionPin::lookup(self::PIN, 1),
            AutorizacionPin::lookup(self::PIN, 1),
        );

        $this->assertNotSame(
            AutorizacionPin::lookup('482913', 1),
            AutorizacionPin::lookup('482914', 1),
        );
    }

    public function test_produce_un_hexadecimal_de_64_caracteres(): void
    {
        // La columna es string(64): un cambio de algoritmo que desborde rompería el insert.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', AutorizacionPin::lookup(self::PIN, 1));
    }
}
