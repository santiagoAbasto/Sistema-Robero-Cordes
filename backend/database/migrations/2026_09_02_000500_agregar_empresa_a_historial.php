<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El historial se consulta desde la ficha de la empresa, así que necesita
 * saber a qué empresa pertenece cada cambio — incluso los que se hicieron
 * sobre un contacto o una cotización de esa empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historial_cambios', function (Blueprint $table) {
            $table->foreignId('empresa_id')->nullable()->after('registro_id')
                ->constrained('empresas')->nullOnDelete();
            $table->index('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::table('historial_cambios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('empresa_id');
        });
    }
};
