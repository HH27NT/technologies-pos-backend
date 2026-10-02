<?php

namespace App\Support\Concerns;

/**
 * Marca un modelo como auditable (mitad "CRUD sensible" de la auditoría híbrida, §15).
 *
 * En Sprint 0 solo aporta la capacidad y el filtrado de campos; el Observer que
 * escribe en `auditoria` los diffs created/updated/deleted se cablea en Sprint 1/12.
 * Por eso aquí no se registra todavía ningún observer ni se escribe en BD.
 */
trait Auditable
{
    /** Campos que nunca se incluyen en el diff de auditoría. */
    public function camposExcluidosAuditoria(): array
    {
        return array_merge(
            ['password_hash', 'remember_token', 'updated_at'],
            property_exists($this, 'excluidosAuditoria') ? $this->excluidosAuditoria : []
        );
    }

    /** Devuelve solo los atributos auditables del arreglo dado (excluye sensibles y ruido). */
    public function filtrarParaAuditoria(array $atributos): array
    {
        return array_diff_key($atributos, array_flip($this->camposExcluidosAuditoria()));
    }
}
