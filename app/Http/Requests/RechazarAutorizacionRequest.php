<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * M14 · Rechazo de una solicitud. El motivo de rechazo es opcional (se audita).
 */
class RechazarAutorizacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
