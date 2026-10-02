<?php

namespace App\Policies;

use App\Models\Usuario;

/**
 * M16 · Autorización de reportes (matriz Fase 7, P21). El OPERADOR (`reportes.ver_limitado`)
 * accede a los reportes operativos de SU turno; el ADMIN (`reportes.ver`) accede a todos,
 * incluidos los de gestión del establecimiento (`verGestion`: inventario, cancelaciones,
 * margen). El acotamiento de los datos del operador a su turno vive en AlcanceReporte.
 */
class ReportePolicy
{
    /** Reportes operativos: ambos roles (con alcance distinto). */
    public function ver(Usuario $usuario): bool
    {
        return $usuario->can('reportes.ver') || $usuario->can('reportes.ver_limitado');
    }

    /** Reportes de gestión del establecimiento: solo ADMIN (P21). */
    public function verGestion(Usuario $usuario): bool
    {
        return $usuario->can('reportes.ver');
    }

    /** Dashboard de decisiones: exclusivo del dueño, ni siquiera el GERENTE lo ve. */
    public function verDashboard(Usuario $usuario): bool
    {
        return $usuario->can('reportes.ver_dashboard');
    }

    /** Exportar un reporte que el usuario puede ver. */
    public function exportar(Usuario $usuario): bool
    {
        return $this->ver($usuario);
    }
}
