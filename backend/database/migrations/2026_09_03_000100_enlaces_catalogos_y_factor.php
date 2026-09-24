<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que pidió Roberto el 02/09:
 *  · enlaces de la empresa (web, redes, mapa) que se abren con un click
 *  · condiciones de pago como lista desplegable
 *  · el factor de conversión calculado según la forma del material
 *  · una moneda marcada como la que viene por defecto
 */
return new class extends Migration
{
    public function up(): void
    {
        // Web, Instagram, Facebook, el mapa… se cargan y quedan clickeables.
        Schema::create('empresa_enlaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->enum('tipo', [
                'Web', 'Instagram', 'Facebook', 'LinkedIn', 'YouTube',
                'WhatsApp', 'Mapa', 'Otro',
            ])->default('Web');
            $table->string('url', 500);
            $table->string('etiqueta', 80)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        // Deja de escribirse a mano: se elige de una lista que ellos arman.
        Schema::create('condiciones_pago', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80)->unique();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('formas', function (Blueprint $table) {
            // Cómo se calculan los kilos por metro de esta forma. Cuando no hay
            // fórmula, el factor no se puede calcular solo y queda a mano.
            $table->enum('formula', [
                'barra_redonda', 'barra_cuadrada', 'barra_hexagonal',
                'cano', 'tubo', 'chapa', 'ninguna',
            ])->default('ninguna')->after('medidas_habituales');

            // Qué medidas hacen falta para poder calcularlo.
            $table->string('medidas_necesarias', 120)->nullable()->after('formula');
        });

        Schema::table('monedas', function (Blueprint $table) {
            // La que se propone sola al cotizar.
            $table->boolean('por_defecto')->default(false)->after('lleva_conversion');
        });

        Schema::table('unidades', function (Blueprint $table) {
            // Para separar en qué se vende de en qué se factura.
            $table->boolean('sirve_para_vender')->default(true)->after('nombre');
            $table->boolean('sirve_para_facturar')->default(true)->after('sirve_para_vender');
            $table->unsignedSmallInteger('orden')->default(0)->after('sirve_para_facturar');
        });

        Schema::table('consulta_lineas', function (Blueprint $table) {
            // Ancho y espesor hacen falta para chapas y caños.
            $table->decimal('ancho_mm', 12, 2)->nullable()->after('espesor_mm');
            // Cuando la forma no permite calcularlo, queda anotado por qué.
            $table->boolean('factor_calculado')->default(false)->after('factor_conversion');
        });
    }

    public function down(): void
    {
        Schema::table('consulta_lineas', function (Blueprint $table) {
            $table->dropColumn(['ancho_mm', 'factor_calculado']);
        });
        Schema::table('unidades', function (Blueprint $table) {
            $table->dropColumn(['sirve_para_vender', 'sirve_para_facturar', 'orden']);
        });
        Schema::table('monedas', function (Blueprint $table) {
            $table->dropColumn('por_defecto');
        });
        Schema::table('formas', function (Blueprint $table) {
            $table->dropColumn(['formula', 'medidas_necesarias']);
        });
        Schema::dropIfExists('condiciones_pago');
        Schema::dropIfExists('empresa_enlaces');
    }
};
