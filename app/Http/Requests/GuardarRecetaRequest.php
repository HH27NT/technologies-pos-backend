<?php

namespace App\Http\Requests;

use App\Support\Tenant\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M07 · Alta/edición de línea de receta (BOM). Producto e insumo deben pertenecer
 * al tenant (validados contra el TenantContext, nunca contra un id del cliente).
 * La unicidad del par producto+insumo la garantiza GestionarRecetaService.
 */
class GuardarRecetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();
        // El producto no cambia en edición: solo se exige en el alta.
        $productoObligatorio = $this->isMethod('POST') ? ['required'] : ['sometimes', 'prohibited'];
        $insumoObligatorio = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'id_producto' => [
                ...$productoObligatorio, 'integer',
                Rule::exists('productos', 'id')->where(
                    fn ($q) => $q->where('id_establecimiento', $tenant)->whereNull('deleted_at')
                ),
            ],
            'id_insumo' => [
                ...$insumoObligatorio, 'integer',
                // El tipo entra en el `exists` a propósito: un insumo de consumo no se
                // descuenta al vender, así que una receta sobre él sería una promesa
                // que el cobro no cumple. Esconderlo en la UI no basta.
                Rule::exists('insumos', 'id')->where(
                    fn ($q) => $q->where('id_establecimiento', $tenant)
                        ->whereNull('deleted_at')
                        ->where('tipo', 'controlado')
                ),
            ],
            'cantidad' => $this->isMethod('POST')
                ? ['required', 'numeric', 'gt:0']
                : ['sometimes', 'required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_producto.required' => 'El producto es obligatorio.',
            'id_producto.prohibited' => 'El producto de una receta no se puede cambiar; elimina la línea y crea otra.',
            'id_producto.exists' => 'El producto no existe en este establecimiento.',
            'id_insumo.required' => 'El insumo es obligatorio.',
            'id_insumo.exists' => 'El insumo no existe en este establecimiento, o es un insumo de consumo que no se descuenta por receta.',
            'cantidad.gt' => 'La cantidad debe ser mayor que cero.',
        ];
    }
}
