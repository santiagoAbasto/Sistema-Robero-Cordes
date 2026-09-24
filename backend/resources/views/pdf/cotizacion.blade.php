<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }} — {{ $nombreEnPdf }}</title>
    <style>
        @page { margin: 18mm 16mm 20mm 16mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9pt;
            color: #0f172a;
            margin: 0;
        }

        .membrete { width: 100%; border-bottom: 1.4pt solid #0f172a; padding-bottom: 6pt; }
        .membrete td { vertical-align: top; }
        /* La marca es el mismo SVG que se ve al entrar al sistema. */
        .marca { width: 34pt; height: 34pt; }
        .razon { font-size: 12pt; font-weight: bold; letter-spacing: .2pt; }
        .datos-casa { font-size: 6.8pt; color: #5b6b7b; }
        .doc { text-align: right; }
        .doc .tipo { font-size: 10.5pt; font-weight: bold; color: #0a6ca6; }
        .doc .num { font-size: 7.5pt; color: #5b6b7b; }

        .cliente { width: 100%; margin-top: 10pt; }
        .cliente td { padding: 2pt 10pt 2pt 0; vertical-align: top; }
        .et { font-size: 6.4pt; color: #90a0ae; text-transform: uppercase; letter-spacing: .3pt; }
        .va { font-size: 8.6pt; font-weight: bold; }

        .lineas { width: 100%; border-collapse: collapse; margin-top: 12pt; }
        .lineas th {
            font-size: 6.6pt; color: #90a0ae; text-transform: uppercase; text-align: left;
            border-bottom: .6pt solid #e6ecf2; padding: 0 4pt 3pt 0; letter-spacing: .3pt;
        }
        .lineas td { font-size: 8.4pt; padding: 4pt 4pt 4pt 0; vertical-align: top; }
        .lineas tr.linea + tr.linea td { border-top: .5pt solid #f1f5f9; }

        /* Las alternativas cuelgan de su linea: sangradas y en gris, para que
           se lean como opciones de ese item y no como items aparte. */
        .lineas tr.opcion td { font-size: 8pt; color: #475569; padding-top: 1pt; padding-bottom: 3pt; }
        .lineas tr.opcion td:first-child { padding-left: 10pt; }
        .lineas .etiqueta {
            display: inline-block; background: #f1f5f9; color: #0a2e45;
            font-weight: bold; font-size: 7.4pt; padding: 1pt 4pt; border-radius: 3pt;
            margin-right: 4pt;
        }
        .der { text-align: right; }
        .item-cliente {
            display: inline-block; background: #eef4f9; color: #0a2e45;
            font-size: 7.2pt; font-weight: bold; padding: 1pt 3pt; border-radius: 2pt;
            margin-right: 3pt;
        }
        .dato-cliente { font-size: 7.2pt; color: #5b6b7b; padding-top: 1pt; }
        .nota-linea { font-size: 7.6pt; color: #334155; padding-top: 1pt; }
        .pedido { font-size: 7pt; color: #9a6207; padding-top: 1pt; }
        .aprox { font-size: 7.4pt; color: #5b6b7b; margin-left: 3pt; }

        .total { width: 100%; margin-top: 6pt; border-top: 1pt solid #0f172a; }
        .total td { padding-top: 5pt; font-size: 10pt; font-weight: bold; }

        /* Plata: simbolo a la izquierda de la celda, numero a la derecha. */
        .plata { width: 100%; border-collapse: collapse; }
        .plata td { padding: 0; font-size: 8.4pt; }
        .plata .s { text-align: left; color: #8496a7; }
        .plata .n { text-align: right; }
        .total .plata td { font-size: 10pt; font-weight: bold; }
        .total .plata .s { color: #5b6b7b; }

        .condiciones { margin-top: 14pt; }
        .condiciones .tit { font-size: 6.6pt; color: #90a0ae; text-transform: uppercase; letter-spacing: .3pt; }
        .condiciones li { font-size: 8.2pt; margin: 2pt 0; }
        .condiciones .condicion { font-size: 8.2pt; margin: 3pt 0; text-align: justify; }
        .condiciones .condicion-tit { font-weight: bold; }

        .pie {
            position: fixed; bottom: -8mm; left: 0; right: 0;
            font-size: 6.8pt; color: #90a0ae;
            border-top: .5pt solid #e6ecf2; padding-top: 4pt;
        }
    </style>
</head>
<body>

<table class="membrete">
    <tr>
        <td style="width:40pt">@if ($marca)<img class="marca" src="{{ $marca }}" alt="CORDES">@endif</td>
        <td>
            <div class="razon">ROBERTO CORDES S.A.</div>
            <div class="datos-casa">
                Palpa 3551, Buenos Aires (C1427EBA) &nbsp;·&nbsp; Tel (+54) 11 4555-3700 &nbsp;·&nbsp; www.cordes.ar
            </div>
        </td>
        <td class="doc">
            <div class="tipo">{{ mb_strtoupper($titulo) }}</div>
            <div class="num">{{ $fecha }}</div>
            @if ($consulta->id_sistema)
                <div class="num">{{ $consulta->id_sistema }}</div>
            @endif
        </td>
    </tr>
</table>

{{-- Los datos de contacto son los que se eligieron al imprimir: la ficha no se toca. --}}
<table class="cliente">
    <tr>
        <td colspan="2">
            <div class="et">Empresa</div>
            <div class="va">{{ $nombreEnPdf }}</div>
        </td>
        <td style="width:150pt">
            <div class="et">Contactar a:</div>
            <div class="va">{{ $contacto ?: '—' }}</div>
        </td>
    </tr>
    <tr>
        <td style="width:170pt">
            <div class="et">Telefono</div>
            <div class="va">{{ $telefono ?: '—' }}</div>
        </td>
        <td>
            <div class="et">Mail</div>
            <div class="va">{{ $mail ?: '—' }}</div>
        </td>
        <td>
            <div class="et">Moneda</div>
            <div class="va">{{ $consulta->moneda?->nombre ?? '—' }}</div>
        </td>
    </tr>
</table>

<table class="lineas">
    <thead>
        <tr>
            <th style="width:42pt">Cant.</th>
            <th style="width:46pt">Unidad</th>
            <th>Descripcion</th>
            @if ($incluyeImportes)
                <th class="der" style="width:80pt">P. unitario</th>
                <th class="der" style="width:80pt">Importe</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($lineas as $linea)
            <tr class="linea">
                <td>{{ $linea->cantidad ? rtrim(rtrim(number_format((float) $linea->cantidad, 2, ',', '.'), '0'), ',') : '' }}</td>
                <td>{{ $linea->unidadVenta?->codigo }}</td>
                <td>
                    {{--
                        Si la linea tiene alternativas, la de arriba tambien
                        lleva su etiqueta. Sin esto el lector ve un precio y no
                        sabe a cual de las opciones corresponde.
                    --}}
                    @if ($linea->tieneAlternativas() && $linea->opcionBase())
                        <span class="etiqueta">{{ $linea->opcionBase()->etiqueta }}</span>
                    @endif
                    {{-- El item del cliente va adelante: es como el compara su
                         requerimiento contra la oferta, renglon por renglon. --}}
                    @if ($linea->item_cliente)
                        <span class="item-cliente">Item {{ $linea->item_cliente }}</span>
                    @endif
                    {{ $linea->descripcion }}@if ($linea->aprox)<span class="aprox">(aprox.)</span>@endif
                    @if ($linea->codigo_cliente)
                        <div class="dato-cliente">Cod. cliente: {{ $linea->codigo_cliente }}</div>
                    @endif
                    {{-- La nota del articulo si sale impresa; la NOTA de la
                         cotizacion es interna y va aparte. --}}
                    @if ($linea->nota)
                        <div class="nota-linea">{!! nl2br(e($linea->nota)) !!}</div>
                    @endif
                    @if ($linea->tieneAlternativas() && $linea->opcionBase()?->plazo_dias)
                        &nbsp;·&nbsp; entrega aprox. {{ $linea->opcionBase()->plazo_dias }} dias
                    @endif
                    {{-- Cuando se cotizó otra cosa, en la hoja sale aclarado. --}}
                    @if (! $linea->igual_a_lo_pedido && $linea->pedido_texto)
                        <div class="pedido">
                            Se habia pedido: {{ $linea->pedido_texto }}
                            @if ($linea->motivo_cambio) &nbsp;·&nbsp; {{ $linea->motivo_cambio }} @endif
                        </div>
                    @endif
                    {{-- Se cotiza por metro y se factura por kilo. --}}
                    @if ($linea->cambiaDeUnidad() && $linea->cantidad_facturar)
                        <div class="pedido" style="color:#0a6ca6">
                            Se factura {{ number_format((float) $linea->cantidad_facturar, 2, ',', '.') }}
                            {{ $linea->unidadFactura?->codigo }}
                            @if ($incluyeImportes && $linea->precio_por_kilo)
                                a {{ $simbolo }}{{ number_format((float) $linea->precio_por_kilo, 2, ',', '.') }}
                                por {{ mb_strtolower($linea->unidadFactura?->codigo ?? '') }}
                            @endif
                        </div>
                    @endif
                </td>
                @if ($incluyeImportes)
                    <td>@include('pdf.plata', ['monto' => $linea->precio_unitario])</td>
                    <td>@include('pdf.plata', ['monto' => $linea->importe])</td>
                @endif
            </tr>

            {{--
                Las alternativas: el mismo item cotizado de otra manera. Van
                debajo de su linea, sangradas, para que se lea que son opciones
                de ESE item y no items aparte. La base no se repite: ya esta
                arriba, en la linea.
            --}}
            @foreach ($linea->opciones->where('es_base', false) as $opcion)
                <tr class="opcion">
                    <td>{{ $opcion->laCantidad() ? rtrim(rtrim(number_format((float) $opcion->laCantidad(), 2, ',', '.'), '0'), ',') : '' }}</td>
                    <td>{{ $linea->unidadVenta?->codigo }}</td>
                    <td>
                        <span class="etiqueta">{{ $opcion->etiqueta }}</span>
                        @if ($opcion->descripcion || $opcion->material)
                            {{ $opcion->descripcion ?: $opcion->material?->nombre }}
                        @endif
                        @if ($opcion->plazo_dias)
                            &nbsp;·&nbsp; entrega aprox. {{ $opcion->plazo_dias }} dias
                        @endif
                        @if ($opcion->nota)
                            <div class="pedido">{{ $opcion->nota }}</div>
                        @endif
                    </td>
                    @if ($incluyeImportes)
                        <td>@include('pdf.plata', ['monto' => $opcion->elPrecio()])</td>
                        <td>@include('pdf.plata', ['monto' => $opcion->importe])</td>
                    @endif
                </tr>
            @endforeach
        @endforeach
    </tbody>
</table>

@if ($incluyeImportes)
    <table class="total">
        <tr>
            <td>Total</td>
            <td style="width:80pt">@include('pdf.plata', ['monto' => $total])</td>
        </tr>
    </table>
@endif

@if ($condiciones->isNotEmpty())
    {{--
        Con titulo van como parrafo —"Entrega: 90 dias corridos..."—, que es
        como se escriben en la hoja. Las sueltas, sin titulo, siguen como lista.
    --}}
    <div class="condiciones">
        <div class="tit">Condiciones</div>

        @foreach ($condiciones->whereNotNull('titulo') as $condicion)
            <div class="condicion">
                <span class="condicion-tit">{{ $condicion->titulo }}:</span>
                {!! nl2br(e($condicion->texto)) !!}
            </div>
        @endforeach

        @php($sueltas = $condiciones->whereNull('titulo'))
        @if ($sueltas->isNotEmpty())
            <ul style="margin:4pt 0 0 12pt; padding:0">
                @foreach ($sueltas as $condicion)
                    <li>{{ $condicion->texto }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif

{{-- La NOTA y las observaciones son de uso interno: sólo salen si se marcan. --}}
@if ($incluyeNota && $consulta->nota)
    <div class="condiciones">
        <div class="tit">Nota</div>
        <div style="font-size:8.2pt; margin-top:3pt">{{ $consulta->nota }}</div>
    </div>
@endif

<div class="pie">
    Cotizo: {{ $consulta->usuario?->initials }}
    &nbsp;·&nbsp; Roberto Cordes S.A. &nbsp;·&nbsp; ISO 9001:2015
    @if ($consulta->vence_el)
        &nbsp;·&nbsp; Validez de la oferta: {{ $consulta->validez_dias }} dias (vence el {{ $consulta->vence_el->format('d/m/Y') }})
    @endif
</div>

</body>
</html>
