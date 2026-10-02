<?php

namespace App\Domain\Impresion;

use App\Models\Impresora;

/**
 * M13 · Elige la impresora activa del tipo apropiado para un documento (D2). Si no hay
 * ninguna activa del tipo, devuelve null ⇒ el ticket se exporta a PDF (P15). El
 * TenantScope acota la búsqueda al establecimiento actual. La preferencia entre tipos
 * candidatos se resuelve en PHP para preservar la paridad SQLite/PostgreSQL.
 */
class SelectorImpresora
{
    public static function para(TipoTicket $tipo): ?Impresora
    {
        $candidatos = $tipo->tiposImpresora();

        $impresoras = Impresora::query()
            ->where('activa', true)
            ->whereIn('tipo', $candidatos)
            ->get();

        foreach ($candidatos as $tipoCandidato) {
            $match = $impresoras->firstWhere('tipo', $tipoCandidato);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }
}
