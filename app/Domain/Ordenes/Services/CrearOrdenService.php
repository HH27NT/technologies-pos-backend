<?php

namespace App\Domain\Ordenes\Services;

use App\Domain\Auditoria\Services\RegistrarAuditoriaService;
use App\Domain\Ordenes\EstadoOrden;
use App\Domain\Ordenes\Events\OrdenCreada;
use App\Models\Establecimiento;
use App\Models\Mesa;
use App\Models\Orden;
use App\Models\SesionCaja;
use App\Support\Exceptions\MesaOcupadaException;
use App\Support\Exceptions\SinCajaAbiertaException;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * M11 · Apertura de una orden (mesa o barra/llevar). Atómica y auditada (§15).
 *
 * Exige caja abierta (regla global 5; la ruta también la protege con EnsureCajaAbierta,
 * pero el servicio es la fuente de verdad). Para serializar el folio continuo por
 * tenant (P12: no reinicia, no duplica) bloquea la fila del establecimiento dentro de
 * la transacción; el índice único (id_establecimiento, folio) es el respaldo en BD.
 *
 * Tipo mesa: lockForUpdate sobre la mesa + verificación "una sola orden abierta por
 * mesa" (respaldo: índice único parcial en pgsql). Tipo barra/llevar: sin mesa.
 */
class CrearOrdenService
{
    public function __construct(
        private readonly RegistrarAuditoriaService $auditoria,
        private readonly TenantContext $tenant,
    ) {}

    public function crear(array $datos): Orden
    {
        return DB::transaction(function () use ($datos) {
            $idEstablecimiento = $this->tenant->id();

            // Serializa la creación de órdenes del tenant => folio continuo seguro (P12).
            Establecimiento::whereKey($idEstablecimiento)->lockForUpdate()->firstOrFail();

            $sesion = SesionCaja::where('estado', 'abierta')->first();
            if ($sesion === null) {
                throw new SinCajaAbiertaException;
            }

            $idMesa = $datos['id_mesa'] ?? null;
            if ($idMesa !== null) {
                // Bloqueo pesimista de la mesa + "una sola orden abierta por mesa".
                Mesa::whereKey($idMesa)->lockForUpdate()->firstOrFail();

                $ocupada = Orden::where('id_mesa', $idMesa)
                    ->where('estado', EstadoOrden::Abierta->value)
                    ->exists();

                if ($ocupada) {
                    throw new MesaOcupadaException;
                }
            }

            $orden = Orden::create([
                'id_sesion_caja' => $sesion->id,
                'id_mesa' => $idMesa,
                'id_tipo_orden' => $datos['id_tipo_orden'],
                'id_usuario' => Auth::id(),
                // Terminal compartida: la persona que firmó con su PIN. Nulo en el modo normal
                // (dispositivo por mesero), donde `id_usuario` YA es el mesero.
                'id_mesero' => $datos['id_mesero'] ?? null,
                'folio' => $this->siguienteFolio($idEstablecimiento),
                'estado' => EstadoOrden::Abierta->value,
                'descuento' => 0,
                'subtotal' => 0,
                'impuesto' => 0,
                'total' => 0,
                'notas' => $datos['notas'] ?? null,
                'abierta_at' => now(),
            ]);

            $this->auditoria->registrar(
                accion: 'orden.creada',
                entidad: 'ordenes',
                entidadId: $orden->id,
                datosDespues: [
                    'folio' => $orden->folio,
                    'id_mesa' => $orden->id_mesa,
                    'id_tipo_orden' => $orden->id_tipo_orden,
                ],
            );

            event(new OrdenCreada($orden));

            return $orden;
        });
    }

    /** Correlativo continuo por tenant (P12), persistido como string con relleno de ceros. */
    private function siguienteFolio(int $idEstablecimiento): string
    {
        $numero = (new Orden)->proximoCorrelativo('folio', $idEstablecimiento);

        return str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    }
}
