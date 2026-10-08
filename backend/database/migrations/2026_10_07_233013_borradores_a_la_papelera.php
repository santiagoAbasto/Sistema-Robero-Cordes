<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
  Los borradores descartados ya no se borran de una: van a la papelera.

  Antes "descartar" era un DELETE y no volvia. Ahora un borrador descartado
  queda con fecha de borrado (deleted_at) y quien lo descarto (eliminada_por):
  se puede restaurar por 30 dias y recien despues se borra para siempre. El
  resto del sistema no lo ve, porque el soft-delete de Laravel lo esconde solo.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultas', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('eliminada_por')->nullable()->after('deleted_at')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('consultas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('eliminada_por');
            $table->dropSoftDeletes();
        });
    }
};
