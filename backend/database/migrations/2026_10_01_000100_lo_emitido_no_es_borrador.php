<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las revisiones (R1, R2) y las copias que ya se emitieron quedaron en
 * Borrador, y la ficha, Consultas por fecha y Seguimiento no las muestran.
 * Desde ahora emitir las pasa a Confirmada; esto arregla las que ya estaban.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('consultas')
            ->where('estado', 'Borrador')
            ->whereNotNull('emitida_el')
            ->update(['estado' => 'Confirmada']);
    }

    public function down(): void
    {
        // No se vuelve atras: no hay forma de saber cuales eran borrador.
    }
};
