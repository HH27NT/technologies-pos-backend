<?php

namespace App\Support\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Envoltura única de respuestas JSON del API (Convenciones §4.2).
 * success siempre presente; message en español; data con el recurso/colección.
 */
class ApiResponse
{
    /** Respuesta de éxito simple. */
    public static function exito(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => self::resolver($data),
        ], $status);
    }

    /** Recurso creado (201). */
    public static function creado(mixed $data = null, string $message = ''): JsonResponse
    {
        return self::exito($data, $message, 201);
    }

    /** Colección paginada con meta/links (Convenciones §4.2 / §4.5). */
    public static function coleccion(LengthAwarePaginator $paginador, string $resourceClass, string $message = ''): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $resourceClass::collection($paginador->items())->resolve(),
            'meta' => [
                'current_page' => $paginador->currentPage(),
                'per_page' => $paginador->perPage(),
                'total' => $paginador->total(),
                'last_page' => $paginador->lastPage(),
            ],
            'links' => [
                'first' => $paginador->url(1),
                'last' => $paginador->url($paginador->lastPage()),
                'prev' => $paginador->previousPageUrl(),
                'next' => $paginador->nextPageUrl(),
            ],
        ], 200);
    }

    /** Respuesta de error con la envoltura estándar. */
    public static function error(string $message, array $errors = [], int $status = 400): JsonResponse
    {
        $cuerpo = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $cuerpo['errors'] = $errors;
        }

        return response()->json($cuerpo, $status);
    }

    private static function resolver(mixed $data): mixed
    {
        return $data instanceof JsonResource ? $data->resolve() : $data;
    }
}
