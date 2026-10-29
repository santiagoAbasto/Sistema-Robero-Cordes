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
    // Lo pone la hoja; sin ella (una linea suelta) no hay columnas de facturacion.
    $conFacturacion ??= false;
    $conIva ??= fn ($monto) => $monto;
    $factorIva ??= 1.0;
    $cantidad = fn ($v) => $v ? rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') : '';
    $metros = fn ($mm) => number_format((float) $mm / 1000, 2, ',', '.');
@endphp
<tr class="{{ $esAlternativa ? 'linea alternativa' : 'linea' }}">
    <td class="item">{{ $numero }}</td>
    <td>@if ($linea->aprox && $linea->cantidad)<span class="aprox">Aprox.</span>@endif{{ $cantidad($linea->cantidad) }}</td>
    <td>{{ $linea->unidadVenta?->codigo }}</td>
    <td>
        {{--
            La alternativa lleva "Alternativa" como titulo y la descripcion
            abajo, alineada con la de la linea. El transporte y el plazo van al
            final del item (ver abajo).
        --}}
        @php
            $entrega = $linea->plazo_dias ? 'entrega aprox. '.$linea->plazo_dias.' días' : '';
            $via = trim($linea->transporte.($linea->transporte && $entrega ? ' · ' : '').$entrega);
        @endphp
        @if ($esAlternativa)
            <div class="alt-titulo">Alternativa</div>
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
        {{-- Un item que no se cotiza queda en la hoja, sin precio. Debajo del
             material va solo el motivo: "no se cotiza" ya lo dice la columna
             del importe (lista del cliente, 14-10-2026). --}}
        @if ($linea->no_cotizada && $linea->motivo_no_cotiza)
            <div class="no-cotiza">{{ $linea->motivo_no_cotiza }}</div>
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
                    {{-- Solo si el precio sale del peso: por metro no entra en la cuenta. --}}
                    @if ($linea->precioSaleDelPeso())
                        &nbsp;&middot;&nbsp; peso calculado sobre el promedio, {{ $metros($linea->largoPromedioMm()) }} m
                    @endif
                @endif
            </div>
        @endif
        {{--
            El transporte y el plazo, como ultimo renglon del item y empezando
            con "Para este ítem" (pedido del cliente, 29-10-2026). Antes iban
            arriba de la descripcion, y el plazo solo salia dos veces.
        --}}
        @if ($via !== '')
            <div class="via">Para este ítem: {{ $via }}</div>
        @endif
    </td>
    {{--
        La cantidad y la unidad en que se factura, y el precio de esa unidad:
        6 barras que se facturan 45,30 KG a $12 el kilo. Una linea que no
        cambia de unidad repite las suyas. La hoja que no tiene ninguna no
        trae estas columnas (ver cotizacion.blade.php).
    --}}
    @php
        $factura = $conFacturacion && $linea->cambiaDeUnidad() && $linea->cantidad_facturar;
    @endphp
    @if ($conFacturacion)
        <td>@if ($linea->aprox && ! $linea->no_cotizada)<span class="aprox">Aprox.</span>@endif{{ $linea->no_cotizada ? '' : $cantidad($factura ? $linea->cantidad_facturar : $linea->cantidad) }}</td>
        <td>{{ $linea->no_cotizada ? '' : ($factura ? $linea->unidadFactura?->codigo : $linea->unidadVenta?->codigo) }}</td>
    @endif
    @if ($incluyeImportes)
        @if ($linea->no_cotizada)
            <td colspan="2" class="no-cotiza-importe">no se cotiza</td>
        @else
            <td>@include('pdf.plata', ['monto' => $conIva($factura && $linea->precio_por_kilo ? $linea->precio_por_kilo : $linea->precio_unitario)])</td>
            <td>@include('pdf.plata', ['monto' => $linea->importeEnLaHoja($factorIva, $conFacturacion)])</td>
        @endif
    @endif
</tr>
