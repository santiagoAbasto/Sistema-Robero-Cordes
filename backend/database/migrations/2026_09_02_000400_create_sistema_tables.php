<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control de cambios y permisos.
 *
 * El historial se anota solo: nadie tiene que acordarse de registrar nada.
 * Dice QUÉ cambió; el POR QUÉ sigue yendo en las observaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('iniciales', 6)->nullable()->after('name');   // RIC, JD, RAC
            $table->boolean('activo')->default(true)->after('role');
        });

        Schema::create('historial_cambios', function (Blueprint $table) {
            $table->id();
            $table->string('tabla', 40);
            $table->unsignedBigInteger('registro_id');
            $table->string('campo', 60)->nullable();
            $table->text('valor_anterior')->nullable();
            $table->text('valor_nuevo')->nullable();
            $table->enum('accion', ['Alta', 'Modificacion', 'Archivado', 'Impresion', 'Vencimiento']);
            // Queda vacío cuando lo hizo el sistema (por ejemplo, un vencimiento).
            $table->foreignId('usuario_id')->nullable()->constrained('users');
            $table->dateTime('fecha');
            $table->timestamps();

            $table->index(['tabla', 'registro_id']);
            $table->index('fecha');
        });

        Schema::create('permisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('ve_fichas', ['Todas', 'Solo las suyas', 'Solo las de un grupo'])->default('Todas');
            $table->boolean('ve_importes')->default(true);
            $table->boolean('ve_notas_de_otros')->default(false);
            $table->boolean('puede_modificar')->default(true);
            $table->boolean('puede_imprimir')->default(true);
            $table->boolean('puede_archivar')->default(false);
            $table->boolean('ve_control_cambios')->default(false);
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permisos');
        Schema::dropIfExists('historial_cambios');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['iniciales', 'activo']);
        });
    }
};
