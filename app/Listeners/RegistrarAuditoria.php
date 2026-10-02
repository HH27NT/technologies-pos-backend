<?php

namespace App\Listeners;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Establecimientos\Events\EstablecimientoCreado;
use App\Domain\Usuarios\Events\RolModificado;
use App\Domain\Usuarios\Events\UsuarioCreado;
use App\Domain\Usuarios\Events\UsuarioModificado;
use Illuminate\Events\Dispatcher;

/**
 * Subscriber SÍNCRONO de auditoría (§13): reacciona a los hechos de negocio y
 * escribe en `auditoria` dentro de la misma transacción que los emitió.
 * No implementa ShouldQueue a propósito (no debe encolarse).
 */
class RegistrarAuditoria
{
    public function __construct(private readonly RegistrarAuditoriaService $auditoria) {}

    public function alCrearEstablecimiento(EstablecimientoCreado $evento): void
    {
        $this->auditoria->registrar(
            accion: 'establecimiento.creado',
            entidad: 'establecimientos',
            entidadId: $evento->establecimiento->id,
            datosDespues: [
                'nombre' => $evento->establecimiento->nombre,
                'activo' => $evento->establecimiento->activo,
                'admin_inicial_id' => $evento->adminInicial->id,
            ],
        );
    }

    public function alCrearUsuario(UsuarioCreado $evento): void
    {
        $this->auditoria->registrar(
            accion: 'usuario.creado',
            entidad: 'usuarios',
            entidadId: $evento->usuario->id,
            datosDespues: $evento->usuario->filtrarParaAuditoria($evento->usuario->getAttributes()),
        );
    }

    public function alModificarUsuario(UsuarioModificado $evento): void
    {
        $this->auditoria->registrar(
            accion: $evento->accion,
            entidad: 'usuarios',
            entidadId: $evento->usuario->id,
            datosAntes: $evento->datosAntes,
            datosDespues: $evento->datosDespues,
        );
    }

    public function alModificarRol(RolModificado $evento): void
    {
        $this->auditoria->registrar(
            accion: $evento->accion,
            entidad: 'roles',
            entidadId: $evento->rol->id,
            datosAntes: $evento->datosAntes,
            datosDespues: $evento->datosDespues,
        );
    }

    /** Mapa evento → método (Convenciones §2.6). */
    public function subscribe(Dispatcher $eventos): array
    {
        return [
            EstablecimientoCreado::class => 'alCrearEstablecimiento',
            UsuarioCreado::class => 'alCrearUsuario',
            UsuarioModificado::class => 'alModificarUsuario',
            RolModificado::class => 'alModificarRol',
        ];
    }
}
