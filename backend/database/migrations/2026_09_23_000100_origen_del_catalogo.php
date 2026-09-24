<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El id que traia cada fila en el sistema anterior.
 *
 * Sin esto la importacion no tiene con que decir que fila del Access es cual
 * material nuestro, y lo unico que queda para emparejar es el nombre — que es
 * justamente el campo que la empresa va a querer corregir: "Titanio Grado 2"
 * alla es "TITANIO GR2" aca. Por eso el importador borraba todo y reescribia:
 * era la unica salida que tenia.
 *
 * Con el id guardado la importacion pasa a ser una fusion: encuentra la fila,
 * actualiza lo que corresponde y deja en paz lo que cargo la empresa. Y se
 * puede correr dos veces sin romper nada.
 *
 * Nullable a proposito: lo que se da de alta en el sistema nuevo no viene de
 * ningun lado y queda en NULL, sin confundirse nunca con lo importado.
 *
 * Las empresas y las cotizaciones ya tenian donde guardarlo —codigo_indice e
 * id_sistema— y nadie lo estaba escribiendo.
 */
return new class extends Migration
{
    private const TABLAS = ['materiales', 'formas', 'unidades'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $t) use ($tabla) {
                $t->string('origen_id', 20)->nullable()->after('id');
                $t->unique('origen_id', "{$tabla}_origen_id_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $t) use ($tabla) {
                $t->dropUnique("{$tabla}_origen_id_unique");
                $t->dropColumn('origen_id');
            });
        }
    }
};
