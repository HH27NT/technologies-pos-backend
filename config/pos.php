<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Política de contraseñas (P16)
    |--------------------------------------------------------------------------
    |
    | Longitud mínima configurable; SIN bloqueo por intentos fallidos (decisión
    | ratificada en Fase 9). Fuente única consumida por App\Support\Validation\
    | PoliticaPassword en todos los Form Requests que validan contraseñas.
    |
    */

    'password' => [
        'min_length' => (int) env('POS_PASSWORD_MIN_LENGTH', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Autorizaciones — clave del índice del PIN (M14.1)
    |--------------------------------------------------------------------------
    |
    | `autorizacion_pins.pin_lookup` es un HMAC-SHA256 determinístico del PIN que
    | permite resolver al autorizador con un WHERE indexado. El espacio de un PIN de
    | 6 dígitos es de solo 10^6: quien conozca la clave y tenga lectura de la BD
    | reconstruye TODOS los PIN en claro con una tabla precomputada en segundos. La
    | clave es, por tanto, tan sensible como los PIN mismos.
    |
    | Va SEPARADA de APP_KEY a propósito:
    |
    |   1. APP_KEY se filtra con facilidad (queda en imágenes, .env de ejemplo,
    |      historiales de git). Un secreto que además desbloquea los PIN no debe
    |      compartir ese destino.
    |   2. Desacoplarlas permite rotar APP_KEY —por incidente o por higiene— sin
    |      invalidar los PIN de todos los autorizadores.
    |
    | No tiene valor por defecto: sin ella la resolución de PIN falla en voz alta en
    | vez de degradarse a una clave adivinable. Genérala con `php artisan pos:pin-key`.
    |
    */

    'autorizacion' => [
        'pin_lookup_key' => env('POS_PIN_LOOKUP_KEY'),
    ],

];
