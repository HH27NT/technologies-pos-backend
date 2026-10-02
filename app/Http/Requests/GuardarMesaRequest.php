<?php

namespace App\Http\Requests;

use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M09 · Alta/edición de mesa. Número y nombre únicos POR establecimiento (con el
 * id_establecimiento del TenantContext, ignorando soft-deletes y la propia fila).
 * El número es opcional al crear: si no se manda, `GuardarMesaService` lo autoasigna
 * (siguiente consecutivo del establecimiento).
 */
class GuardarMesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();

        return [
            'numero' => [
                'sometimes', 'nullable', 'integer', 'min:1',
                Rule::unique('mesas', 'numero')
                    ->where(fn ($q) => $q->where('id_establecimiento', $tenant)->whereNull('deleted_at'))
                    ->ignore($this->route('id')),
            ],
            'nombre' => [
                'sometimes', 'nullable', 'string', 'max:50',
                Rule::unique('mesas', 'nombre')
                    ->where(fn ($q) => $q->where('id_establecimiento', $tenant)->whereNull('deleted_at'))
                    ->ignore($this->route('id')),
            ],
            'zona' => ['sometimes', 'nullable', 'string', 'max:50'],
            'capacidad' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'activa' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'numero.unique' => 'Ya existe una mesa con ese número en este establecimiento.',
            'nombre.unique' => 'Ya existe una mesa con ese nombre en este establecimiento.',
        ];
    }
}
