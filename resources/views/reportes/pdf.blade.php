<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $reporte['titulo'] ?? 'Reporte' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .rango { color: #444; margin-bottom: 2px; }
        .zona { color: #999; font-size: 9px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #f0f0f0; }
        .resumen td { border: none; padding: 2px 6px; }
        .resumen th { border: none; text-align: left; background: none; }
    </style>
</head>
<body>
    <h1>{{ $reporte['titulo'] ?? 'Reporte' }}</h1>

    @if (! empty($reporte['rango']))
        {{-- Fechas legibles y en la zona del establecimiento, con el último día INCLUIDO:
             `rango.fin` es exclusivo y enseñarlo tal cual alargaba el periodo un día. --}}
        <div class="rango">
            {{ $reporte['rango']['preset_etiqueta'] ?? 'Periodo' }}:
            {{ $reporte['rango']['etiqueta'] ?? '' }}
        </div>
        @if (! empty($reporte['rango']['zona_horaria']))
            <div class="zona">Zona horaria: {{ $reporte['rango']['zona_horaria'] }}</div>
        @endif
    @endif

    <table>
        <thead>
            <tr>
                @foreach ($reporte['columnas'] ?? [] as $columna)
                    <th>{{ $columna }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($reporte['filas'] ?? [] as $fila)
                <tr>
                    @foreach ((array) $fila as $celda)
                        <td>{{ is_scalar($celda) || $celda === null ? $celda : json_encode($celda) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ max(1, count($reporte['columnas'] ?? [])) }}">Sin datos en el periodo.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if (! empty($reporte['resumen']))
        <table class="resumen">
            @foreach ($reporte['resumen'] as $clave => $valor)
                <tr>
                    <th>{{ ucfirst(str_replace('_', ' ', $clave)) }}</th>
                    <td>{{ is_scalar($valor) || $valor === null ? $valor : json_encode($valor) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
