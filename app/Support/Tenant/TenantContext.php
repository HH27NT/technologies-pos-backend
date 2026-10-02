<?php

namespace App\Support\Tenant;

/**
 * Contexto de tenant de la petición actual (singleton).
 *
 * Lo fija el middleware ResolveTenant (Sprint 1) tras autenticar. El SUPER_ADMIN
 * (id_establecimiento nulo) no fija contexto salvo impersonación (P17), por lo que
 * queda exento del TenantScope. En Sprint 0 se usa desde tests y seeders.
 */
class TenantContext
{
    protected ?int $idEstablecimiento = null;

    protected bool $impersonando = false;

    /** Fija el establecimiento activo. $impersonando marca el acceso de soporte del super_admin. */
    public function set(?int $idEstablecimiento, bool $impersonando = false): void
    {
        $this->idEstablecimiento = $idEstablecimiento;
        $this->impersonando = $impersonando;
    }

    /** Id del establecimiento activo (o null si no hay contexto, p. ej. super_admin). */
    public function id(): ?int
    {
        return $this->idEstablecimiento;
    }

    /** True si hay un establecimiento fijado (es decir, las consultas deben filtrarse por tenant). */
    public function has(): bool
    {
        return $this->idEstablecimiento !== null;
    }

    /** True si el contexto corresponde a una impersonación auditada del super_admin (P17). */
    public function impersonando(): bool
    {
        return $this->impersonando;
    }

    /** Limpia el contexto (fin de la petición / pruebas). */
    public function olvidar(): void
    {
        $this->idEstablecimiento = null;
        $this->impersonando = false;
    }
}
