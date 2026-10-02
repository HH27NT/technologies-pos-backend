<?php

namespace App\Domain\Auditoria\Services;

use App\Models\Auditoria;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * Escribe en la bitácora append-only `auditoria` (§15).
 *
 * Se invoca DENTRO de la transacción de la acción de negocio (consistencia
 * transaccional, §15): si la acción se revierte, su auditoría también.
 */
class RegistrarAuditoriaService
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function registrar(
        string $accion,
        string $entidad,
        ?int $entidadId = null,
        ?array $datosAntes = null,
        ?array $datosDespues = null,
    ): Auditoria {
        return Auditoria::create([
            'id_establecimiento' => $this->tenant->id(),
            'id_usuario' => Auth::id(),
            'accion' => $accion,
            'entidad' => $entidad,
            'entidad_id' => $entidadId,
            'datos_antes' => $datosAntes,
            'datos_despues' => $datosDespues,
            'ip' => request()?->ip(),
        ]);
    }
}
