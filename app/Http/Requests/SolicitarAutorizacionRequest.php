<?php

namespace App\Http\Requests;

use App\Domain\Autorizaciones\TipoAutorizacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M14 · Solicitud de operación sensible (forma polimórfica por `tipo`). La existencia y
 * el estado del sujeto (orden modificable, ítem no cancelado, insumo del tenant) son de
 * ESTADO y los valida SolicitarAutorizacionService (404 cross-tenant / 422 estado).
 */
class SolicitarAutorizacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tipo = $this->input('tipo');
        $esOrden = in_array($tipo, [TipoAutorizacion::CancelarItem->value, TipoAutorizacion::AnularOrden->value], true);
        $esInventario = in_array($tipo, [TipoAutorizacion::EntradaStock->value, TipoAutorizacion::AjusteStock->value], true);

        return [
            'tipo' => ['required', Rule::enum(TipoAutorizacion::class)],
            'motivo' => ['required', 'string', 'max:500'],
            'id_orden' => [Rule::requiredIf($esOrden), 'integer'],
            'id_item' => [Rule::requiredIf($tipo === TipoAutorizacion::CancelarItem->value), 'integer'],
            'id_insumo' => [Rule::requiredIf($esInventario), 'integer'],
            'cantidad' => [Rule::requiredIf($esInventario), 'numeric', 'gt:0'],
            'costo_unitario' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.required' => 'El tipo de autorización es obligatorio.',
            'motivo.required' => 'El motivo es obligatorio.',
            'cantidad.gt' => 'La cantidad debe ser mayor que cero.',
        ];
    }
}
