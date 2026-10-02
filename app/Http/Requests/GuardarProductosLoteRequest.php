<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaInsumoDelProducto;
use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M06 · Alta de productos por lote (carga inicial del menú). Espeja las reglas de
 * GuardarProductoRequest fila por fila; los errores salen indexados
 * ("productos.3.nombre") para que la rejilla del frontend señale la fila exacta.
 *
 * El tope de 100 filas coincide con el máximo de paginación del API (§4.5): mantiene
 * la petición acotada y obliga al cliente a partir un pegado enorme en tandas, en vez
 * de que el servidor lo rechace entero después de haberlo procesado a medias.
 */
class GuardarProductosLoteRequest extends FormRequest
{
    use ValidaInsumoDelProducto;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();

        return [
            'productos' => ['required', 'array', 'min:1', 'max:100'],
            'productos.*.id_categoria' => [
                'required', 'integer',
                Rule::exists('categorias_producto', 'id')->where(fn ($q) => $q->where('id_establecimiento', $tenant)->whereNull('deleted_at')),
            ],
            'productos.*.nombre' => ['required', 'string', 'max:120'],
            'productos.*.descripcion' => ['sometimes', 'nullable', 'string'],
            'productos.*.precio_venta' => ['required', 'numeric', 'min:0'],
            'productos.*.costo_referencia' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'productos.*.controla_inventario' => ['sometimes', 'boolean'],
            'productos.*.disponible' => ['sometimes', 'boolean'],
            'productos.*.sku' => ['sometimes', 'nullable', 'string', 'max:50'],
            ...$this->reglasDeInsumo('productos.*.insumo', $tenant),
        ];
    }

    public function messages(): array
    {
        return [
            'productos.required' => 'No se recibió ningún producto.',
            'productos.min' => 'No se recibió ningún producto.',
            'productos.max' => 'Se pueden crear como máximo 100 productos por vez.',
            'productos.*.nombre.required' => 'El nombre es obligatorio.',
            'productos.*.nombre.max' => 'El nombre no puede pasar de 120 caracteres.',
            'productos.*.id_categoria.required' => 'La categoría es obligatoria.',
            'productos.*.id_categoria.exists' => 'La categoría no existe en este establecimiento.',
            'productos.*.precio_venta.required' => 'El precio de venta es obligatorio.',
            'productos.*.precio_venta.numeric' => 'El precio de venta debe ser un número.',
            'productos.*.precio_venta.min' => 'El precio de venta no puede ser negativo.',
            'productos.*.costo_referencia.min' => 'El costo de referencia no puede ser negativo.',
            ...$this->mensajesDeInsumo('productos.*.insumo'),
        ];
    }
}
