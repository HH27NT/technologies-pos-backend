<?php

namespace App\Domain\Autorizaciones;

/**
 * M14 · Origen de una autorización. Fuente única del literal de `autorizaciones.metodo`.
 *
 * - `asincrono`: el operador SOLICITA (pendiente) y el admin resuelve desde la bandeja.
 * - `override`: el operador ejecuta la operación al instante tecleando la contraseña de
 *   un admin (patrón "override de gerente"); la autorización nace ya `aprobada`.
 */
enum MetodoAutorizacion: string
{
    case Asincrono = 'asincrono';
    case Override = 'override';
}
