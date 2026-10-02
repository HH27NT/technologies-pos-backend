<?php

namespace App\Http\Requests;

use App\Models\Mesa;
use App\Models\TipoOrden;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * M11 · Apertura de orden. Forma: tipo de orden válido (global) y, si lo hay, mesa
 * existente y activa del tenant. La coherencia "tipo mesa ⇒ mesa obligatoria" se
 * valida aquí (422). La ocupación de la mesa (una sola orden abierta) y la caja
 * abierta son reglas de ESTADO: viven en CrearOrdenService (409).
 */
class CrearOrdenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_tipo_orden' => ['required', 'integer', 'exists:tipos_orden,id'],
            'id_mesa' => ['nullable', 'integer'],
            'notas' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Firma del mesero en terminal compartida. Token opaco emitido por
            // `POST /terminal/identificar`; jamás un id (sería falsificable). El controlador lo
            // resuelve; si no existe o expiró, la orden queda sin firma en vez de fallar: el
            // mesero se equivocó de tiempo, no de venta.
            'mesero_token' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tipo = TipoOrden::find($this->input('id_tipo_orden'));
            $esMesa = $tipo !== null && $tipo->nombre === 'mesa';
            $idMesa = $this->input('id_mesa');

            if ($esMesa && empty($idMesa)) {
                $validator->errors()->add('id_mesa', 'La mesa es obligatoria para una orden de tipo mesa.');

                return;
            }

            // Mesa indicada: debe existir, estar activa y ser del tenant (TenantScope).
            if (! empty($idMesa)) {
                $mesa = Mesa::find($idMesa);
                if ($mesa === null || ! $mesa->activa) {
                    $validator->errors()->add('id_mesa', 'La mesa no está disponible.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'id_tipo_orden.required' => 'El tipo de orden es obligatorio.',
            'id_tipo_orden.exists' => 'El tipo de orden no es válido.',
        ];
    }
}
