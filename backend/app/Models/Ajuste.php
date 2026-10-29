<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un valor de Configuracion sin tabla propia: el texto del envio, la casilla. */
class Ajuste extends Model
{
    protected $table = 'ajustes';

    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function valor(string $clave, ?string $porDefecto = null): ?string
    {
        return static::find($clave)?->valor ?? $porDefecto;
    }

    public static function guardar(string $clave, ?string $valor): void
    {
        static::updateOrCreate(['clave' => $clave], ['valor' => $valor]);
    }
}
