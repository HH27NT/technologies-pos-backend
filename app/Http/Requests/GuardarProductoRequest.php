<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaInsumoDelProducto;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M06 · Alta/edición de producto. La categoría debe pertenecer al mismo tenant
 * (validada contra el id_establecimiento del TenantContext, nunca del cliente).
 */
class GuardarProductoRequest extends FormRequest
{
    use ValidaInsumoDelProducto;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();
        $obligatorio = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'id_categoria' => [
                ...$obligatorio, 'integer',
                Rule::exists('categorias_producto', 'id')->where(fn ($q) => $q->where('id_establecimiento', $tenant)->whereNull('deleted_at')),
            ],
            'nombre' => [...$obligatorio, 'string', 'max:120'],
            'descripcion' => ['sometimes', 'nullable', 'string'],
            'precio_venta' => [...$obligatorio, 'numeric', 'min:0'],
            'costo_referencia' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'controla_inventario' => ['sometimes', 'boolean'],
            'disponible' => ['sometimes', 'boolean'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:50'],
            ...$this->reglasDeInsumo('insumo', $tenant),
        ];
    }

    public function messages(): array
    {
        return [
            'id_categoria.required' => 'La categoría es obligatoria.',
            'id_categoria.exists' => 'La categoría no existe en este establecimiento.',
            'precio_venta.min' => 'El precio de venta no puede ser negativo.',
            'costo_referencia.min' => 'El costo de referencia no puede ser negativo.',
            ...$this->mensajesDeInsumo('insumo'),
        ];
    }
}
