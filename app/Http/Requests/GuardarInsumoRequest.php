<?php

namespace App\Http\Requests;

use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M08 · Alta/edición de insumo. La unidad debe ser propia del tenant o global (P4);
 * el proveedor, propio del tenant. stock_actual NO se acepta aquí: la existencia
 * solo cambia por movimientos (D2). El tenant se toma del TenantContext.
 *
 * `stock_inicial` es la única excepción, y solo al CREAR: un atajo de UI para no
 * obligar a un segundo viaje a "Registrar movimiento" nada más dar de alta el
 * insumo. `GuardarInsumoService` lo traduce a un movimiento de entrada real en la
 * misma transacción — no es un campo de `insumos`, así que en edición se rechaza.
 */
class GuardarInsumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();
        $obligatorio = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'nombre' => [...$obligatorio, 'string', 'max:120'],
            'tipo' => ['sometimes', Rule::in(['controlado', 'consumo'])],
            'id_unidad_medida' => [
                ...$obligatorio, 'integer',
                Rule::exists('unidades_medida', 'id')->where(
                    fn ($q) => $q->where('id_establecimiento', $tenant)->orWhereNull('id_establecimiento')
                ),
            ],
            'id_proveedor' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('proveedores', 'id')->where(
                    fn ($q) => $q->where('id_establecimiento', $tenant)->whereNull('deleted_at')
                ),
            ],
            'stock_minimo' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'costo_unitario' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock_inicial' => $this->isMethod('POST')
                ? ['sometimes', 'nullable', 'numeric', 'min:0.001']
                : ['prohibited'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del insumo es obligatorio.',
            'tipo.in' => 'El tipo de insumo debe ser controlado o de consumo.',
            'id_unidad_medida.required' => 'La unidad de medida es obligatoria.',
            'id_unidad_medida.exists' => 'La unidad de medida no existe o no pertenece a este establecimiento.',
            'id_proveedor.exists' => 'El proveedor no existe en este establecimiento.',
            'stock_minimo.min' => 'El stock mínimo no puede ser negativo.',
            'costo_unitario.min' => 'El costo unitario no puede ser negativo.',
            'stock_inicial.min' => 'La existencia inicial debe ser mayor a cero.',
            'stock_inicial.prohibited' => 'La existencia inicial solo se captura al crear el insumo; después se ajusta con un movimiento.',
        ];
    }
}
