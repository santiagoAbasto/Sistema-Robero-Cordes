<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * La clave de la forma.
 *
 * Es el nombre corto y estable con el que se la referencia: "barra_redonda".
 * El nombre visible puede corregirse —"BARRA REDONDA" a "Barra redonda"— sin
 * que se rompa nada que apunte a ella.
 *
 * De paso se va la columna "formula", que ya no contiene ninguna formula: era
 * una clave vieja de agrupacion, y tener dos claves para lo mismo confunde a
 * quien lea esto en un año. La cuenta vive en "expresion".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formas', function (Blueprint $table) {
            $table->string('clave', 60)->nullable()->after('id');
        });

        foreach (DB::table('formas')->get(['id', 'nombre']) as $forma) {
            DB::table('formas')->where('id', $forma->id)->update([
                'clave' => $this->claveLibre($forma->nombre, $forma->id),
            ]);
        }

        Schema::table('formas', function (Blueprint $table) {
            $table->string('clave', 60)->nullable(false)->unique()->change();
        });

        if (Schema::hasColumn('formas', 'formula')) {
            Schema::table('formas', function (Blueprint $table) {
                $table->dropColumn('formula');
            });
        }
    }

    public function down(): void
    {
        Schema::table('formas', function (Blueprint $table) {
            $table->dropUnique(['clave']);
            $table->dropColumn('clave');
            $table->string('formula')->nullable();
        });
    }

    /** "BARRA REDONDA" -> "barra_redonda". Si ya existe, le suma el id. */
    private function claveLibre(string $nombre, int $id): string
    {
        $base = Str::snake(Str::ascii(mb_strtolower($nombre)));
        $base = trim(preg_replace('/[^a-z0-9_]+/', '_', $base) ?? '', '_') ?: 'forma';

        $tomada = DB::table('formas')->where('clave', $base)->where('id', '!=', $id)->exists();

        return $tomada ? $base.'_'.$id : $base;
    }
};
