<?php

namespace App\Domain\Impresion;

use App\Domain\Ordenes\EstadoItem;
use App\Models\ConfiguracionEstablecimiento;
use App\Models\Orden;

/**
 * M13 · Constructor PURO del `contenido_json` del ticket desde la configuración del
 * establecimiento y la orden (sin estado ni efectos secundarios). La comanda NO lleva
 * precios; el ticket de cobro incluye los importes congelados de la orden y los pagos.
 */
class ContenidoTicket
{
    /** Comanda para cocina/barra: solo lo enviado, sin precios. */
    public static function comanda(Orden $orden, ?ConfiguracionEstablecimiento $config): array
    {
        return [
            'tipo' => TipoTicket::Comanda->value,
            'establecimiento' => $config?->nombre_comercial,
            'orden' => self::cabeceraOrden($orden),
            'items' => $orden->detalles
                ->where('estado_item', EstadoItem::Activo->value)
                ->where('enviado', true)
                ->map(fn ($d) => [
                    'cantidad' => (float) $d->cantidad,
                    'producto' => $d->producto?->nombre,
                ])->values()->all(),
        ];
    }

    /** Ticket de cobro: comprobante con precios, totales congelados y pagos. */
    public static function cobro(Orden $orden, ?ConfiguracionEstablecimiento $config, ?string $folioTicket): array
    {
        return [
            'tipo' => TipoTicket::Cobro->value,
            'nombre_comercial' => $config?->nombre_comercial,
            'telefono' => $config?->telefono_ticket,
            'direccion' => $config?->direccion_ticket,
            'orden' => self::cabeceraOrden($orden),
            'atendio' => self::atendio($orden),
            'folio_ticket' => $folioTicket,
            'items' => $orden->detalles
                ->where('estado_item', EstadoItem::Activo->value)
                ->map(fn ($d) => [
                    'cantidad' => (float) $d->cantidad,
                    'producto' => $d->producto?->nombre,
                    'precio_unitario' => (float) $d->precio_unitario,
                    'subtotal' => (float) $d->subtotal,
                ])->values()->all(),
            'totales' => [
                'subtotal' => (float) $orden->subtotal,
                'descuento' => (float) $orden->descuento,
                'impuesto' => (float) $orden->impuesto,
                'total' => (float) $orden->total,
            ],
            'pagos' => $orden->pagos->map(fn ($p) => [
                'tipo' => $p->tipoPago?->nombre,
                'monto' => (float) $p->monto,
            ])->values()->all(),
        ];
    }

    /**
     * Nombre de quien ATENDIÓ la mesa, para el comprobante del cliente.
     *
     * Deliberadamente NO es quien cobró. Con terminal compartida un compañero puede cerrar
     * la mesa mientras el mesero sigue en el piso: esa firma queda en el pago, en la auditoría
     * y en el reporte —donde sirve para el arqueo— pero al cliente le importa quién lo
     * atendió, y en pago dividido "cobró" sería una lista de nombres en un papel de 58 mm.
     *
     * Con firma de PIN la cuenta es la de la tablet: si la relación no vino cargada preferimos
     * no imprimir nombre antes que atribuirle el servicio al dispositivo (misma regla que
     * `features/ordenes/atribucion.ts` en el frontend).
     */
    private static function atendio(Orden $orden): ?string
    {
        if ($orden->id_mesero) {
            return $orden->mesero?->nombre;
        }

        return $orden->usuario?->nombre;
    }

    private static function cabeceraOrden(Orden $orden): array
    {
        return [
            'folio' => $orden->folio,
            'tipo' => $orden->tipoOrden?->nombre,
            'mesa' => $orden->mesa?->numero,
        ];
    }
}
