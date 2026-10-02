<?php

namespace App\Support\Pin;

use RuntimeException;

/**
 * Clave dedicada del índice HMAC de los PIN (autorización M14.1 y mesero).
 *
 * Extraída de `AutorizacionPin` al aparecer el segundo consumidor: la regla de "sin clave se
 * falla, no se degrada" debe vivir en un solo sitio, o el día que alguien añada un tercer tipo
 * de PIN la copiará mal.
 */
class ClaveLookup
{
    /**
     * Sin configurar es un error de despliegue, no un caso a degradar: caer a una clave vacía
     * o adivinable convertiría el índice en el hash desnudo que este diseño existe para evitar
     * (6 dígitos se rompen con una tabla de 10^6 entradas).
     *
     * @throws RuntimeException si POS_PIN_LOOKUP_KEY no está configurada.
     */
    public static function obtener(): string
    {
        $clave = config('pos.autorizacion.pin_lookup_key');

        if (blank($clave)) {
            throw new RuntimeException(
                'POS_PIN_LOOKUP_KEY no está configurada. Genérala con `php artisan pos:pin-key` '
                .'y añádela al .env; sin ella los PIN no pueden resolverse.'
            );
        }

        return (string) $clave;
    }
}
