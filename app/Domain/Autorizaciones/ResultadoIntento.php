<?php

namespace App\Domain\Autorizaciones;

/**
 * M14.1 · Por qué se rechazó un override por PIN. Fuente única del literal de
 * `autorizacion_intentos.resultado`.
 *
 * - `pin_invalido`: el PIN no resuelve a nadie del tenant, no verifica, o el autorizador
 *   está inactivo. Al operador se le responde siempre lo mismo (422 genérico): distinguir
 *   los casos filtraría qué PINs existen.
 * - `sin_permiso`: el PIN resuelve a un usuario real que NO puede autorizar esa operación.
 */
enum ResultadoIntento: string
{
    case PinInvalido = 'pin_invalido';
    case SinPermiso = 'sin_permiso';
}
