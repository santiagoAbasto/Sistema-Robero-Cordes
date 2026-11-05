<?php

use App\Services\CatalogoDeCondiciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las condiciones de trabajo de la ficha, de texto libre a una tabla.
 *
 * Pedido del cliente (04-11-2026): "hoy las condiciones en las fichas son texto libre, debe ser
 * una tabla: que todo lo que escriban sea de la tabla y no cualquier cosa". Ahora hay una lista
 * de condiciones (los títulos: "Tipo de pago", "Flete") y, de cada una, la lista de sus datos
 * ("A cargo del cliente"). La ficha sólo deja elegir de ahí.
 *
 * NO SE PIERDE NADA. Los campos cargados siguen con su título y su dato como texto (de ahí
 * sale la cotización); la tabla sólo se suma. Se siembra sola con lo que ya está cargado:
 * los títulos que figuran en las fichas y en los juegos de condiciones, y los datos que se
 * repiten. Lo que se escribió una sola vez queda como estaba, marcado "fuera de la lista", para
 * que alguien lo revise (Configuración > Condiciones de la ficha). No se inventa ningún dato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_de_condicion', function (Blueprint $tabla) {
            $tabla->id();
            // Es el título tal cual sale en la cotización: el mismo largo que el del campo.
            $tabla->string('nombre', 60)->unique();
            $tabla->unsignedInteger('orden')->default(0);
            // Al elegir esta condición en una ficha, "se usa al cotizar" viene tildado.
            $tabla->boolean('proponer_al_cotizar')->default(false);
            $tabla->boolean('activo')->default(true);
            $tabla->timestamps();
        });

        Schema::create('opciones_de_condicion', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('tipo_de_condicion_id')->constrained('tipos_de_condicion')->cascadeOnDelete();
            $tabla->string('texto', 255);
            $tabla->unsignedInteger('orden')->default(0);
            $tabla->boolean('activo')->default(true);
            $tabla->timestamps();

            $tabla->unique(['tipo_de_condicion_id', 'texto'], 'opciones_de_condicion_unicas');
        });

        Schema::table('empresa_campos', function (Blueprint $tabla) {
            $tabla->foreignId('tipo_de_condicion_id')->nullable()->constrained('tipos_de_condicion')->nullOnDelete();
            $tabla->foreignId('opcion_de_condicion_id')->nullable()->constrained('opciones_de_condicion')->nullOnDelete();
        });

        (new CatalogoDeCondiciones)->sembrar();
    }

    public function down(): void
    {
        Schema::table('empresa_campos', function (Blueprint $tabla) {
            $tabla->dropConstrainedForeignId('opcion_de_condicion_id');
            $tabla->dropConstrainedForeignId('tipo_de_condicion_id');
        });

        Schema::dropIfExists('opciones_de_condicion');
        Schema::dropIfExists('tipos_de_condicion');
    }
};
