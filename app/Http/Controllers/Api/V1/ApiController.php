<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/**
 * Base de los controllers del API v1: habilita policies ($this->authorize) y la
 * resolución de paginación según Convenciones §4.5 (default 15, máximo 100).
 */
abstract class ApiController extends Controller
{
    use AuthorizesRequests;

    protected function perPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', 15);

        return max(1, min($perPage, 100));
    }

    /**
     * Filtro de texto uniforme para los índices (parámetro "buscar"). Compara en
     * minúsculas porque LIKE distingue mayúsculas en PostgreSQL (producción) pero no
     * en SQLite (pruebas): sin esto la búsqueda pasaría los tests y fallaría en el bar.
     *
     * Las condiciones van agrupadas: el OR entre columnas no debe escaparse del resto
     * de filtros — buscar "clara" dentro de una categoría tiene que seguir siendo
     * dentro de esa categoría. Los comodines del término se escapan con "!" (soportado
     * por ambos motores vía ESCAPE) para que "50%" busque ese texto y no lo devuelva todo.
     *
     * Compromiso conocido: LOWER() sobre la columna impide aprovechar un índice. Con
     * catálogos de bar (cientos de filas) es irrelevante; si alguna tabla crece, la
     * salida es un índice funcional sobre LOWER(columna).
     *
     * @param  string[]  $columnas  Nombres de columna del propio código, nunca del request.
     */
    protected function buscarEn(Builder $consulta, string $termino, array $columnas): Builder
    {
        $patron = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($termino))).'%';

        return $consulta->where(function (Builder $q) use ($columnas, $patron) {
            foreach ($columnas as $columna) {
                $q->orWhereRaw("LOWER({$columna}) LIKE ? ESCAPE '!'", [$patron]);
            }
        });
    }
}
