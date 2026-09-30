<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Largos variables: las barras y los canos no vienen todos del mismo largo.
 *
 * Se compran y se venden por rango —"de 2,80 a 3,20 m"— y hasta ahora habia
 * que elegir un numero y escribir el rango a mano en la descripcion, donde no
 * lo lee ninguna cuenta.
 *
 * Son dos columnas y no tres: que el largo sea variable es tener los dos
 * extremos cargados. Un tilde aparte podria quedar marcado con el rango vacio,
 * o sin marcar con el rango puesto, y entonces habria que decidir cual de los
 * dos gana.
 *
 * El largo que usan el peso y el factor sigue siendo largo_mm: cuando hay
 * rango, se le guarda el promedio. Asi ninguna cuenta se entera de esto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $tabla) {
            $tabla->decimal('largo_min_mm', 12, 2)->nullable()->after('largo_mm');
            $tabla->decimal('largo_max_mm', 12, 2)->nullable()->after('largo_min_mm');
        });
    }

    public function down(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $tabla) {
            $tabla->dropColumn(['largo_min_mm', 'largo_max_mm']);
        });
    }
};
