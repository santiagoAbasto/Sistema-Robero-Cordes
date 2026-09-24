<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos del Índice Telefónico.
 *
 * Son las listas que el sistema propone en vez de dejar escribir libre: así se
 * puede filtrar después y el mismo dato no queda cargado de dos formas distintas.
 * Todas llevan `activo` porque nada se borra — lo que se deja de usar se desactiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paises', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80)->unique();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('provincias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pais_id')->constrained('paises');
            $table->string('nombre', 80);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['pais_id', 'nombre']);
        });

        Schema::create('localidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provincia_id')->constrained('provincias');
            $table->string('nombre', 120);
            $table->string('codigo_postal', 12)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['provincia_id', 'nombre']);
        });

        Schema::create('rubros', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80)->unique();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Barra redonda, caño, chapa… la forma en que viene el material.
        Schema::create('formas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60)->unique();
            $table->string('medidas_habituales', 120)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('materiales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80)->unique();
            $table->string('familia', 60)->nullable();
            $table->foreignId('forma_habitual_id')->nullable()->constrained('formas');
            // Kilos por metro para un diámetro de referencia; se usa para proponer
            // el factor de conversión cuando se cotiza por metro y se factura por kilo.
            $table->decimal('densidad', 8, 4)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Las distintas formas en que escriben cada material: HAST C276, HASTELLOY
        // C-276, HAST. C-276… Es lo que hace que el buscador los encuentre igual.
        Schema::create('material_alias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materiales')->cascadeOnDelete();
            $table->string('alias', 80);
            $table->timestamps();
            $table->index('alias');
        });

        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 8)->unique();   // UN, C/U, MT, KG, TN
            $table->string('nombre', 40);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // No alcanza con "dólar": hay que saber contra qué referencia se toma el cambio.
        Schema::create('monedas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60)->unique();
            $table->string('moneda_base', 30);
            $table->string('referencia', 80)->nullable();
            $table->boolean('lleva_conversion')->default(true);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Antes telex y fax, hoy mail y WhatsApp, mañana lo que aparezca.
        Schema::create('tipos_medio', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 40)->unique();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Condiciones que se repiten en casi todas las cotizaciones.
        Schema::create('condiciones_habituales', function (Blueprint $table) {
            $table->id();
            $table->string('texto', 200);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('condiciones_habituales');
        Schema::dropIfExists('tipos_medio');
        Schema::dropIfExists('monedas');
        Schema::dropIfExists('unidades');
        Schema::dropIfExists('material_alias');
        Schema::dropIfExists('materiales');
        Schema::dropIfExists('formas');
        Schema::dropIfExists('rubros');
        Schema::dropIfExists('localidades');
        Schema::dropIfExists('provincias');
        Schema::dropIfExists('paises');
    }
};
