<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La calculadora de peso.
 *
 * Hasta ahora cada forma tenia una clave ('barra_redonda') y la cuenta estaba
 * escrita en el codigo, en dos lugares distintos. Ahora la formula vive con la
 * forma: se puede corregir una densidad o agregar una forma sin tocar codigo,
 * y los dos lados del sistema leen la misma cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formas', function (Blueprint $table) {
            // Las medidas que pide esta forma: [{"clave":"diameter","label":"Diametro"}]
            $table->json('campos')->nullable()->after('formula');
            // La cuenta, en texto. Devuelve volumen en cm3.
            $table->text('expresion')->nullable()->after('campos');
            // Los caños se eligen de una tabla en vez de cargar las medidas.
            $table->boolean('usa_cano')->default(false)->after('expresion');
        });

        Schema::table('materiales', function (Blueprint $table) {
            $table->string('uns', 20)->nullable()->after('densidad');
            $table->string('w_nr', 20)->nullable()->after('uns');
        });

        Schema::create('canos_estandar', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');              // 1 pulgada
            $table->string('schedule', 20);        // 40
            $table->decimal('diametro_mm', 8, 2);  // 33.40
            $table->decimal('pared_mm', 8, 2);     //  3.38
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['nombre', 'schedule']);
        });

        Schema::table('consulta_lineas', function (Blueprint $table) {
            // La foto completa del calculo: medidas, unidades, densidad y
            // formula usadas ese dia. Si mañana cambia una densidad, las
            // cotizaciones viejas siguen diciendo lo que dijeron.
            $table->json('calculo')->nullable()->after('factor_calculado');
            $table->decimal('peso_kg', 12, 3)->nullable()->after('calculo');
        });
    }

    public function down(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $table) {
            $table->dropColumn(['calculo', 'peso_kg']);
        });

        Schema::dropIfExists('canos_estandar');

        Schema::table('materiales', function (Blueprint $table) {
            $table->dropColumn(['uns', 'w_nr']);
        });

        Schema::table('formas', function (Blueprint $table) {
            $table->dropColumn(['campos', 'expresion', 'usa_cano']);
        });
    }
};
