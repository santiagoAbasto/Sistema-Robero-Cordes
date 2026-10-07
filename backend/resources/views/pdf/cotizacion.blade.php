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

        /* Las alternativas cuelgan de su linea (1.1, 1.2): en gris y pegadas
           a ella, para que se lean como opciones de ese item. */
        .lineas td.item { font-weight: bold; color: #0a2e45; }
        .lineas tr.alternativa td { color: #475569; border-top: none !important; padding-top: 2pt; }
        .lineas tr.alternativa td.item { color: #64748b; padding-left: 6pt; }
        /* "Alternativa" como titulo del renglon; la via de una linea comun. */
        .lineas .alt-titulo { font-weight: bold; color: #0a2e45; font-size: 7.8pt; padding-bottom: 1.5pt; }
        .lineas .via { font-weight: bold; color: #0a6ca6; font-size: 7.4pt; padding-bottom: 1pt; }
        .total-nota { font-weight: normal; font-size: 7.5pt; color: #64748b; }
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
                &nbsp;·&nbsp; ventas@cordes.ar
            </div>
        </td>
        <td class="doc">
            <div class="tipo">{{ mb_strtoupper($titulo) }}</div>
            {{--
                El numero con su revision: 2026-0001 R0, R1. Es por el que
                pregunta el cliente, y la revision le dice cual de las hojas
                que tiene es la ultima.
            --}}
            @if ($consulta->numeroConRevision())
                <div class="num"><strong>N° {{ $consulta->numeroConRevision() }}</strong></div>
            @endif
            {{-- Sin emitir no es una cotizacion todavia: que no se confunda con una. --}}
            @if (! $consulta->estaEmitida())
                <div class="num" style="color:#b45309"><strong>BORRADOR</strong></div>
            @endif
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
            <th style="width:26pt">Item</th>
            <th style="width:38pt">Cant.</th>
            <th style="width:40pt">Unidad</th>
            <th>Descripcion</th>
            @if ($incluyeImportes)
                <th class="der" style="width:76pt">P. unitario</th>
                <th class="der" style="width:76pt">Importe</th>
            @endif
        </tr>
    </thead>
    <tbody>
        {{--
            Items 1, 2, 3 y, debajo de cada uno, sus alternativas 1.1, 1.2.
            La alternativa de una linea que se quito tampoco sale.
        --}}
        @php
            $madres = $lineas->filter(fn ($l) => ! $l->esAlternativa())->values();
            $hayAlternativas = false;
        @endphp
        @foreach ($madres as $i => $linea)
            @include('pdf.linea', ['linea' => $linea, 'numero' => $i + 1, 'esAlternativa' => false])
            @foreach ($lineas->where('alternativa_de_id', $linea->id)->values() as $k => $alternativa)
                @php $hayAlternativas = true; @endphp
                @include('pdf.linea', ['linea' => $alternativa, 'numero' => ($i + 1).'.'.($k + 1), 'esAlternativa' => true])
            @endforeach
        @endforeach
    </tbody>
</table>

@if ($incluyeImportes)
    <table class="total">
        <tr>
            <td>Total @if ($hayAlternativas)<span class="total-nota">(sin considerar las alternativas)</span>@endif</td>
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
