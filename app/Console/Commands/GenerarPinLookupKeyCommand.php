<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * M14.1 · Genera la clave HMAC del índice de PIN (`POS_PIN_LOOKUP_KEY`).
 *
 * Es el equivalente de `key:generate` para el secreto que protege `autorizacion_pins.
 * pin_lookup`. Existe como comando porque un secreto que hay que fabricar a mano se
 * termina copiando del ejemplo — y una clave compartida entre despliegues anula el
 * propósito del índice con clave (ver App\Models\AutorizacionPin::lookup()).
 *
 * Rotarla invalida los PIN vigentes: el HMAC deja de coincidir y cada autorizador debe
 * volver a fijar el suyo. Por eso sobrescribir exige --force.
 */
class GenerarPinLookupKeyCommand extends Command
{
    protected $signature = 'pos:pin-key
        {--show : Solo mostrar la clave, sin escribir el .env}
        {--force : Sobrescribir la clave existente (INVALIDA los PIN vigentes)}';

    protected $description = 'Genera la clave del índice HMAC de los PIN de autorización (POS_PIN_LOOKUP_KEY).';

    public function handle(): int
    {
        $clave = 'base64:'.base64_encode(random_bytes(32));

        if ($this->option('show')) {
            $this->line($clave);

            return self::SUCCESS;
        }

        $ruta = base_path('.env');

        if (! is_file($ruta)) {
            $this->error('No hay un archivo .env en la raíz del proyecto.');
            $this->line('Crea uno con `cp .env.example .env`, o usa --show y cópiala a mano.');

            return self::FAILURE;
        }

        $contenido = (string) file_get_contents($ruta);
        $tieneValor = filled($this->valorActual($contenido));

        if ($tieneValor && ! $this->option('force')) {
            $this->error('POS_PIN_LOOKUP_KEY ya tiene un valor.');
            $this->warn('Rotarla invalida el PIN de TODOS los autorizadores: cada uno tendrá que volver a fijarlo en /app/mi-pin.');
            $this->line('Si aun así quieres rotarla, repite el comando con --force.');

            return self::FAILURE;
        }

        file_put_contents($ruta, $this->reemplazar($contenido, $clave));

        $this->info('POS_PIN_LOOKUP_KEY escrita en .env.');

        if ($tieneValor) {
            $this->warn('Clave rotada: los PIN vigentes ya no resuelven. Avisa a los autorizadores para que fijen el suyo de nuevo.');
        }

        $this->line('Recuerda limpiar la caché de config si la tenías compilada: php artisan config:clear');

        return self::SUCCESS;
    }

    /** Valor actual de la variable en el .env (null si la línea no existe). */
    private function valorActual(string $contenido): ?string
    {
        return preg_match('/^POS_PIN_LOOKUP_KEY=(.*)$/m', $contenido, $coincidencias) === 1
            ? trim($coincidencias[1])
            : null;
    }

    /**
     * Sustituye la línea existente o la añade al final. Se reemplaza por texto plano
     * (no con preg_replace) para que los caracteres de base64 no se interpreten como
     * referencias de captura.
     */
    private function reemplazar(string $contenido, string $clave): string
    {
        $linea = 'POS_PIN_LOOKUP_KEY='.$clave;

        if (preg_match('/^POS_PIN_LOOKUP_KEY=.*$/m', $contenido, $coincidencias) === 1) {
            return str_replace($coincidencias[0], $linea, $contenido);
        }

        return rtrim($contenido, "\r\n")."\n\n".$linea."\n";
    }
}
