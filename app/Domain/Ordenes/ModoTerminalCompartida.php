<?php

namespace App\Domain\Ordenes;

use App\Models\ConfiguracionEstablecimiento;
use App\Support\Exceptions\TerminalCompartidaInactivaException;
use App\Support\Tenant\TenantContext;

/**
 * Interruptor por tenant del modo "terminal compartida".
 *
 * Existe como concepto propio, y no como un `if` suelto sobre la configuración, porque decide
 * si `id_mesero` debe llenarse: sin él, cada consumidor tendría que acordarse de comprobarlo y
 * el día que uno se olvide, un establecimiento con dispositivo por mesero empezaría a recibir
 * firmas de PIN que no pidió.
 *
 * ⚠️ La fila se sigue resolviendo con un `where` explícito por el `TenantContext`, aunque
 * `ConfiguracionEstablecimiento` ya use `BelongsToTenant`. El TenantScope **no filtra cuando no
 * hay contexto** (workers, consola), y ahí un `first()` devolvería la configuración de otro
 * local: con contexto el `where` es redundante, y sin contexto es lo único que evita encender
 * el modo de terminal compartida en un establecimiento que no lo pidió. Este servicio ya tuvo
 * ese bug exacto. Ver `ConfiguracionController`, que filtra igual por el mismo motivo.
 */
class ModoTerminalCompartida
{
    private ?ConfiguracionEstablecimiento $configuracion = null;

    private bool $resuelta = false;

    public function __construct(private readonly TenantContext $tenant) {}

    public function activo(): bool
    {
        return (bool) $this->configuracion()?->terminal_compartida;
    }

    /** Segundos de inactividad tras los que la tablet se bloquea sola. */
    public function segundosBloqueo(): int
    {
        return (int) ($this->configuracion()?->bloqueo_terminal_segundos ?: 120);
    }

    /** @throws TerminalCompartidaInactivaException */
    public function exigirActivo(): void
    {
        if (! $this->activo()) {
            throw new TerminalCompartidaInactivaException;
        }
    }

    /**
     * Configuración del establecimiento activo, o null si no hay contexto de tenant
     * (super_admin sin impersonar). Se memoriza porque `activo()` y `segundosBloqueo()`
     * se llaman juntos en la misma petición.
     */
    private function configuracion(): ?ConfiguracionEstablecimiento
    {
        if (! $this->resuelta) {
            $this->configuracion = $this->tenant->has()
                ? ConfiguracionEstablecimiento::where('id_establecimiento', $this->tenant->id())->first()
                : null;
            $this->resuelta = true;
        }

        return $this->configuracion;
    }
}
