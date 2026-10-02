<?php

namespace App\Support\Concerns;

/**
 * Concern para generar un correlativo continuo por establecimiento (P12).
 *
 * Es infraestructura: lo consumirá CrearOrdenService en Sprint 7 para el folio de
 * orden (secuencia continua por tenant, sin reinicio). Aquí no se invoca en ningún
 * flujo; solo queda disponible. La generación segura ante concurrencia (bloqueo)
 * es responsabilidad del servicio que lo use, dentro de su transacción.
 */
trait GeneraFolio
{
    /**
     * Próximo correlativo entero para una columna numérica, acotado por tenant.
     *
     * @param  string  $columna  Columna que almacena el correlativo (p. ej. 'folio').
     * @param  int|null  $idEstablecimiento  Tenant; si es null usa el de la propia instancia.
     */
    public function proximoCorrelativo(string $columna, ?int $idEstablecimiento = null): int
    {
        $tenant = $idEstablecimiento ?? $this->getAttribute('id_establecimiento');

        $query = static::query()->withoutGlobalScopes();

        if ($tenant !== null) {
            $query->where('id_establecimiento', $tenant);
        }

        // Extrae la parte numérica del correlativo más alto y suma 1.
        $ultimo = $query->max($columna);

        $numero = $ultimo === null ? 0 : (int) preg_replace('/\D/', '', (string) $ultimo);

        return $numero + 1;
    }
}
