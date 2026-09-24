<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ficha de la empresa y todo lo que cuelga de ella.
 *
 * Regla que atraviesa todas estas tablas: si no tenemos el dato, queda en blanco.
 * Lo único imprescindible es el nombre (y el CUIT en las razones sociales, porque
 * sin eso no se puede facturar). Nada se borra: se archiva con `activa`/`activo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_indice', 20)->nullable();   // el número que hoy tiene en el índice
            $table->string('codigo_isis', 20)->nullable();     // uno solo por empresa, tal cual viene
            $table->string('nombre', 150);
            $table->string('cuit', 13)->nullable();
            $table->string('direccion', 150)->nullable();
            $table->foreignId('localidad_id')->nullable()->constrained('localidades');
            $table->foreignId('provincia_id')->nullable()->constrained('provincias');
            $table->foreignId('pais_id')->nullable()->constrained('paises');
            $table->string('codigo_postal', 12)->nullable();
            $table->foreignId('rubro_id')->nullable()->constrained('rubros');
            $table->text('observacion_general')->nullable();   // la que se ve siempre arriba de la ficha
            $table->enum('visible_para', ['Todos', 'Solo los dueños', 'Un grupo'])->default('Todos');
            $table->boolean('activa')->default(true);
            $table->foreignId('creada_por')->nullable()->constrained('users');
            $table->foreignId('modificada_por')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('nombre');
            $table->index('cuit');
            $table->index('codigo_isis');
        });

        // Una empresa puede ser cliente y proveedor a la vez. "Agenda general" es
        // para las que no son ninguna de las comerciales.
        Schema::create('empresa_relacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->enum('relacion', ['Cliente', 'Proveedor', 'Servicio', 'Empleado', 'Agenda general']);
            $table->date('desde')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->unique(['empresa_id', 'relacion']);
        });

        // El "Contactar a:". Antes era uno solo por empresa.
        Schema::create('contactos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 120);
            $table->string('sector', 60)->nullable();
            $table->string('cargo', 60)->nullable();
            $table->boolean('principal')->default(false);   // el que se propone al imprimir
            $table->text('observacion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['empresa_id', 'principal']);
        });

        // Cada persona puede tener varios teléfonos, WhatsApp y mails.
        Schema::create('contacto_medios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contacto_id')->constrained('contactos')->cascadeOnDelete();
            $table->foreignId('tipo_medio_id')->constrained('tipos_medio');
            $table->string('valor', 120);
            $table->boolean('principal')->default(false);
            $table->string('nota', 80)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index('valor');   // el buscador de arriba busca por teléfono
        });

        // A quién se le factura. Un mismo cliente puede facturar a varias.
        Schema::create('razones_sociales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('razon_social', 150);
            $table->string('cuit', 13);
            $table->enum('condicion_iva', [
                'Resp. Inscripto', 'Monotributo', 'Exento', 'Consumidor Final',
            ])->nullable();
            $table->enum('iibb_condicion', ['No inscripto', 'Local', 'Convenio multilateral'])->nullable();
            $table->foreignId('iibb_provincia_sede_id')->nullable()->constrained('provincias');
            $table->string('iibb_numero', 20)->nullable();
            $table->date('inicio_actividades')->nullable();
            $table->string('direccion_fiscal', 150)->nullable();
            $table->string('localidad', 80)->nullable();
            $table->boolean('habitual')->default(false);
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->index('cuit');
        });

        // Las condiciones de trabajo: cada empresa arma los campos que necesita.
        Schema::create('empresa_campos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('titulo', 60);
            $table->text('valor')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            // Si va en Sí, el dato se propone solo al cotizarle a esa empresa
            // y al copiarle una cotización de otra.
            $table->boolean('usar_al_cotizar')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_campos');
        Schema::dropIfExists('razones_sociales');
        Schema::dropIfExists('contacto_medios');
        Schema::dropIfExists('contactos');
        Schema::dropIfExists('empresa_relacion');
        Schema::dropIfExists('empresas');
    }
};
