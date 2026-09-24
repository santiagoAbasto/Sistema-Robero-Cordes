{{--
    Un importe con su simbolo.

    El simbolo va en su propia celda a la izquierda y el numero a la derecha:
    asi los US$ quedan uno abajo del otro y las cifras tambien. Con el simbolo
    flotado, dompdf tira el numero al renglon siguiente cuando el conjunto no
    entra en la columna.

    $simbolo lo hereda de la vista que incluye.
--}}
@if ($monto)
    <table class="plata">
        <tr>
            <td class="s">{{ $simbolo }}</td>
            <td class="n">{{ number_format((float) $monto, 2, ',', '.') }}</td>
        </tr>
    </table>
@endif
