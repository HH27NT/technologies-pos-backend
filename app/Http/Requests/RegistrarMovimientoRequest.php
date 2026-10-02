<?php

namespace App\Http\Requests;

use App\Domain\Inventario\TipoMovimiento;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M08 · Registro de un movimiento manual de inventario. Solo tipos manuales
 * (venta/salida los origina el cobro, Sprint 8). cantidad > 0 siempre; motivo
 * obligatorio en ajuste/merma/rotura/consumo_interno (regla global 19; los tipos
 * los dicta TipoMovimiento, fuente única).
 *
 * M14.1 · Override: entrada/ajuste son ADMIN directo (`inventario.entrada`/`.ajustar`); un
 * OPERADOR sin ese permiso puede ejecutarlos tecleando el PIN de autorización de un admin.
 * Cuando NO hay permiso directo sobre entrada/ajuste, se exige el bloque de override (PIN de
 * 6 dígitos + motivo). merma/rotura/consumo_interno los registra el operador directo y NO
 * admiten override.
 */
class RegistrarMovimientoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();
        $requiereOverride = $this->requiereOverride();

        return [
            'id_insumo' => [
                'required', 'integer',
                Rule::exists('insumos', 'id')->where(
                    fn ($q) => $q->where('id_establecimiento', $tenant)->whereNull('deleted_at')
                ),
            ],
            'tipo' => ['required', Rule::in(TipoMovimiento::manuales())],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'costo_unitario' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'motivo' => [
                Rule::requiredIf(fn () => $requiereOverride || in_array($this->input('tipo'), TipoMovimiento::conMotivo(), true)),
                'nullable', 'string', 'max:500',
            ],
            'autorizacion_pin' => [Rule::requiredIf($requiereOverride), 'nullable', 'digits:6'],
            'terminal' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    /** True si es entrada/ajuste y el usuario NO tiene el permiso directo (→ override). */
    private function requiereOverride(): bool
    {
        $permiso = match ($this->input('tipo')) {
            'entrada' => 'inventario.entrada',
            'ajuste' => 'inventario.ajustar',
            default => null,
        };

        return $permiso !== null && $this->user() !== null && ! $this->user()->can($permiso);
    }

    public function messages(): array
    {
        return [
            'id_insumo.required' => 'El insumo es obligatorio.',
            'id_insumo.exists' => 'El insumo no existe en este establecimiento.',
            'tipo.in' => 'Tipo de movimiento inválido.',
            'cantidad.gt' => 'La cantidad debe ser mayor que cero.',
            'motivo.required' => 'El motivo es obligatorio para ajustes, mermas, roturas, consumo interno y autorizaciones por override.',
            'autorizacion_pin.required' => 'El PIN de autorización es obligatorio.',
            'autorizacion_pin.digits' => 'El PIN de autorización debe tener 6 dígitos.',
        ];
    }
}
