<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Celular, aparte de Telefono.
 *
 * "Como telefono manda un celular, tendriamos que marcar si es celular porque
 * el discado es distinto para celular que para linea fija". Desde otro pais,
 * un celular argentino se marca con un 9 despues del 54; un fijo no. Y
 * WhatsApp solo anda con un celular.
 *
 * Es un tipo de medio y no una marca sobre el telefono: ya hay Telefono, Fax,
 * WhatsApp y Mail, y cada uno se elige de la misma lista. Un tilde "es
 * celular" aparte podria quedar puesto en un fax.
 *
 * Los 1.163 telefonos cargados quedan como estan. Cuales son celulares no se
 * puede saber mirando el numero —un 11 4555-3700 y un 11 3106-1795 tienen el
 * mismo largo— y adivinar cambiaria como se los llama.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('tipos_medio')->where('nombre', 'Celular')->exists()) {
            return;
        }

        DB::table('tipos_medio')->insert([
            'nombre' => 'Celular',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Solo si nadie lo uso: borrar el tipo dejaria medios sin tipo.
        $id = DB::table('tipos_medio')->where('nombre', 'Celular')->value('id');

        if ($id && ! DB::table('contacto_medios')->where('tipo_medio_id', $id)->exists()) {
            DB::table('tipos_medio')->where('id', $id)->delete();
        }
    }
};
