<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alternativas de una linea.
 *
 * Es el mismo item cotizado de varias maneras, dentro de la misma cotizacion.
 * Pasa seguido:
 *
 *  · por transporte  — maritimo 201 USD y 80 dias, aereo 210 USD y 40 dias
 *  · por cantidad    — 36,6 m a 141,60; 73,2 m a 96,10; 109,8 m a 81,00
 *  · por material    — el mismo aporte en ALLOY 20 o en INCONEL 625
 *
 * Cada alternativa pisa solo lo que cambia. Lo que deja vacio lo hereda de la
 * linea: si solo cambia el precio, no hay que repetir la cantidad ni la medida.
 *
 * Una de ellas es la base: es la que cuenta para el total de la cotizacion.
 * Las demas se imprimen abajo como opciones, para que el cliente elija.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consulta_linea_opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_linea_id')->constrained('consulta_lineas')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);

            // Como se la nombra en la cotizacion: "Aereo", "73,2 m", "INCONEL 625".
            $table->string('etiqueta', 60);
            // Por que es distinta. Sirve para agrupar y para el texto impreso.
            $table->string('tipo', 20)->default('Otra');

            // Lo que cambia. Lo que queda en null se hereda de la linea.
            $table->decimal('cantidad', 12, 2)->nullable();
            $table->decimal('precio_unitario', 12, 2)->nullable();
            $table->decimal('precio_por_kilo', 12, 2)->nullable();
            $table->unsignedSmallInteger('plazo_dias')->nullable();
            $table->foreignId('material_id')->nullable()->constrained('materiales');
            $table->string('descripcion')->nullable();
            $table->string('nota', 200)->nullable();

            // La que cuenta para el total. Hay exactamente una por linea.
            $table->boolean('es_base')->default(false);

            $table->timestamps();

            $table->index(['consulta_linea_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consulta_linea_opciones');
    }
};
