<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Autorizaciones\Services\OverrideAutorizacionService;
use App\Domain\Autorizaciones\TipoAutorizacion;
use App\Domain\Inventario\Services\RegistrarMovimientoService;
use App\Http\Requests\RegistrarMovimientoRequest;
use App\Http\Resources\MovimientoInventarioResource;
use App\Models\Autorizacion;
use App\Models\MovimientoInventario;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * M08 · Registro de movimientos manuales del ledger (MovimientoInventarioPolicy).
 * La autorización depende del tipo: entrada/ajuste son solo ADMIN; merma/rotura/
 * consumo_interno los puede registrar también el OPERADOR.
 *
 * M14.1 · entrada/ajuste admiten override: un OPERADOR sin el permiso directo los ejecuta
 * tecleando el PIN de autorización de un admin.
 */
class MovimientoController extends ApiController
{
    /** tipo de movimiento → habilidad de la policy. */
    private const ABILITY = [
        'entrada' => 'registrarEntrada',
        'ajuste' => 'registrarAjuste',
        'merma' => 'registrarMerma',
        'rotura' => 'registrarMerma',
        'consumo_interno' => 'registrarMerma',
    ];

    /** tipo de movimiento con override → tipo de autorización (M14). */
    private const TIPO_AUTORIZACION = [
        'entrada' => TipoAutorizacion::EntradaStock,
        'ajuste' => TipoAutorizacion::AjusteStock,
    ];

    public function store(RegistrarMovimientoRequest $request, RegistrarMovimientoService $service, OverrideAutorizacionService $override): JsonResponse
    {
        $datos = $request->validated();
        $tipo = $datos['tipo'];
        $usuario = $request->user();

        if ($usuario->can(self::ABILITY[$tipo], MovimientoInventario::class)) {
            $movimiento = $service->registrar($datos);
        } elseif (isset(self::TIPO_AUTORIZACION[$tipo]) && $usuario->can('autorizaciones.solicitar')) {
            $movimiento = null;
            $override->ejecutar(
                tipo: self::TIPO_AUTORIZACION[$tipo],
                payload: $datos,
                entidadId: (int) $datos['id_insumo'],
                datos: [
                    'id_insumo' => (int) $datos['id_insumo'],
                    'tipo' => $tipo,
                    'cantidad' => (float) $datos['cantidad'],
                    'costo_unitario' => isset($datos['costo_unitario']) ? (float) $datos['costo_unitario'] : null,
                ],
                ejecutar: function (Autorizacion $a) use (&$movimiento, $service, $datos) {
                    $movimiento = $service->registrar([...$datos, 'id_autorizacion' => $a->id]);
                },
                ip: $request->ip(),
            );
        } else {
            $this->authorize(self::ABILITY[$tipo], MovimientoInventario::class);
        }

        return ApiResponse::creado(
            MovimientoInventarioResource::make($movimiento),
            'Movimiento registrado correctamente.'
        );
    }
}
