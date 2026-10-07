{{--
    Un renglon de la hoja: una linea o una de sus alternativas.

    Las dos se imprimen igual —cantidad, unidad, descripcion, precio,
    importe— porque una alternativa es una linea entera: otra medida, otro
    material, otra via. Lo unico distinto es el numero (1.1 debajo del 1) y
    que la alternativa no repite lo que habia pedido el cliente: es otra
    respuesta al mismo pedido.

    Recibe $linea, $numero y $esAlternativa; $incluyeImportes y $simbolo los
    hereda de la hoja.
--}}
@php
    $cantidad = fn ($v) => $v ? rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') : '';
    $metros = fn ($mm) => number_format((float) $mm / 1000, 2, ',', '.');
@endphp
<tr class="{{ $esAlternativa ? 'linea alternativa' : 'linea' }}">
    <td class="item">{{ $numero }}</td>
    <td>{{ $cantidad($linea->cantidad) }}</td>
    <td>{{ $linea->unidadVenta?->codigo }}</td>
    <td>
        {{--
            La alternativa lleva "Alternativa" como titulo, con su via y su
            plazo, y la descripcion abajo, alineada con la de la linea. La via
            de una linea comun va como un renglon chico arriba de la descripcion.
        --}}
        @php
            $entrega = $linea->plazo_dias ? 'entrega aprox. '.$linea->plazo_dias.' dias' : '';
            $via = trim($linea->transporte.($linea->transporte && $entrega ? ' · ' : '').$entrega);
        @endphp
        @if ($esAlternativa)
            <div class="alt-titulo">Alternativa{{ $via !== '' ? ' · '.$via : '' }}</div>
        @elseif ($via !== '')
            <div class="via">{{ $via }}</div>
        @endif
        {{-- El item del cliente va adelante: es como el compara su
             requerimiento contra la oferta, renglon por renglon. --}}
        @if ($linea->item_cliente)
            <span class="item-cliente">Su item {{ $linea->item_cliente }}</span>
        @endif
        {{ $linea->descripcion }}
        {{-- Con o sin costura, la norma: si la descripcion se escribio a
             mano y no la trae, se agrega. --}}
        @if ($linea->caracteristicas && ! str_contains(mb_strtoupper($linea->descripcion), mb_strtoupper($linea->caracteristicas)))
            {{ $linea->caracteristicas }}
        @endif
        @if ($linea->aprox)<span class="aprox">(aprox.)</span>@endif
        @if (! $esAlternativa && ! $linea->transporte && $linea->plazo_dias)
            <div class="nota-linea">Entrega aprox. {{ $linea->plazo_dias }} dias</div>
        @endif
        @if ($linea->codigo_cliente)
            <div class="dato-cliente">Cod. cliente: {{ $linea->codigo_cliente }}</div>
        @endif
        {{-- La nota del articulo si sale impresa; la NOTA de la cotizacion es
             interna y va aparte. --}}
        @if ($linea->nota)
            <div class="nota-linea">{!! nl2br(e($linea->nota)) !!}</div>
        @endif
        {{-- Cuando se cotizo otra cosa, en la hoja sale aclarado. --}}
        @if (! $esAlternativa && ! $linea->igual_a_lo_pedido && $linea->pedido_texto)
            <div class="pedido">
                Se habia pedido: {{ $linea->pedido_texto }}
                @if ($linea->motivo_cambio) &nbsp;&middot;&nbsp; {{ $linea->motivo_cambio }} @endif
            </div>
        @endif
        {{--
            Largos variables. Con un rango, el rango y el promedio con que se
            calculo. Con los dos extremos iguales no se sabe el rango —"me
            dicen aprox. 3 metros"—, y "de 3 a 3" se leia como un largo exacto.
        --}}
        @if ($linea->largoEsVariable())
            <div class="pedido">
                @if ((float) $linea->largo_min_mm === (float) $linea->largo_max_mm)
                    Largos variables, aprox. {{ $metros($linea->largo_min_mm) }} m
                @else
                    Largos de {{ $metros($linea->largo_min_mm) }} a {{ $metros($linea->largo_max_mm) }} m
                    &nbsp;&middot;&nbsp; peso calculado sobre el promedio, {{ $metros($linea->largoPromedioMm()) }} m
                @endif
            </div>
        @endif
        {{-- Se cotiza en una unidad y se factura en otra. --}}
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
