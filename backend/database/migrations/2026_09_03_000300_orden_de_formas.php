<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Las formas se ordenan a mano: primero las que mas se usan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formas', function (Blueprint $table) {
            $table->unsignedSmallInteger('orden')->default(0)->after('usa_cano');
        });

        // Las que ya estaban arrancan por orden alfabetico, para no quedar todas
        // en cero y salir en cualquier orden.
        $i = 0;

        foreach (DB::table('formas')->orderBy('nombre')->pluck('id') as $id) {
            DB::table('formas')->where('id', $id)->update(['orden' => ++$i]);
        }
    }

    public function down(): void
    {
        Schema::table('formas', function (Blueprint $table) {
            $table->dropColumn('orden');
        });
    }
};
