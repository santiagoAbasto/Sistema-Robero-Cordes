<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CondicionHabitual extends Model
{
    protected $table = 'condiciones_habituales';

    protected $guarded = [];

    protected $casts = [
        'activo' => 'boolean',
        'por_defecto' => 'boolean',
    ];

    /**
     * Las condiciones de un juego: importacion o stock.
     *
     * Sin juego no devuelve nada, y esta bien que asi sea: los dos juegos
     * difieren en el plazo de entrega y en la forma de pago, asi que elegir por
     * la persona seria mandar la mitad de las ofertas con el plazo del otro
     * caso.
     */
    public static function delJuego(?string $juego)
    {
        if (blank($juego)) {
            return static::query()->whereRaw('1 = 0')->get();
        }

        return static::query()
            ->where('activo', true)
            ->where('por_defecto', true)
            ->where('juego', $juego)
            ->orderBy('orden')
            ->get();
    }

    /**
     * El texto ya resuelto para una cotizacion.
     */
    public function textoPara(Consulta $consulta): string
    {
        return static::conFecha($this->texto, $consulta);
    }

    /**
     * Reemplaza {vence} por la fecha hasta la que vale la oferta.
     *
     * Se guarda el marcador y no una fecha fija: una plantilla con la fecha de
     * otra cotizacion es justamente lo que no puede pasar. Lo resuelve el
     * servidor en cada guardado, asi cambiar la validez actualiza el texto.
     *
     * Sin vencimiento se saca el marcador y la frase arranca en mayuscula:
     * antes que dejar un hueco a la vista, la condicion se lee igual.
     */
    public static function conFecha(string $texto, Consulta $consulta): string
    {
        if (! str_contains($texto, '{vence}')) {
            return $texto;
        }

        $vence = $consulta->vence_el?->format('d-m-Y');

        if ($vence !== null) {
            return str_replace('{vence}', $vence, $texto);
        }

        $sinToken = ltrim(str_replace('{vence}', '', $texto), ", \t\n");

        return mb_strtoupper(mb_substr($sinToken, 0, 1)).mb_substr($sinToken, 1);
    }
}
