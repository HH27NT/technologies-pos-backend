<?php

namespace App\Support\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * M14.1 · Rechaza los PINs que un atacante probaría primero: todos los dígitos iguales
 * (000000, 111111...) y las secuencias consecutivas ascendentes o descendentes (123456,
 * 987654...). El espacio es de 10^6, así que estos candidatos obvios pesan de más.
 */
class PinNoTrivial implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pin = (string) $value;

        if (! preg_match('/^\d{6}$/', $pin)) {
            return;  // La forma la valida `digits:6`; aquí solo juzgamos PINs bien formados.
        }

        if ($this->repetido($pin) || $this->consecutivo($pin)) {
            $fail('El PIN es demasiado predecible. Evita dígitos repetidos o secuencias.');
        }
    }

    private function repetido(string $pin): bool
    {
        return count(array_unique(str_split($pin))) === 1;
    }

    /** True si cada dígito es el anterior +1 (o siempre -1), con envoltura decimal aparte. */
    private function consecutivo(string $pin): bool
    {
        foreach ([1, -1] as $paso) {
            $esSecuencia = true;

            for ($i = 1; $i < 6; $i++) {
                if ((int) $pin[$i] !== (int) $pin[$i - 1] + $paso) {
                    $esSecuencia = false;
                    break;
                }
            }

            if ($esSecuencia) {
                return true;
            }
        }

        return false;
    }
}
