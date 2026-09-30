<?php

use App\Models\Consulta;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versiones de una cotizacion: 2026-0001 R0, R1, R2.
 *
 * "La original es la version 0 y despues 1, 2, 3". "Si ya la emitiste no la
 * podes modificar, pero si te deja como base y hace un borrador".
 *
 * Cada revision es su propia fila y no una foto guardada aparte. Es lo que
 * pidieron: lo emitido queda congelado —con sus lineas, sus condiciones y su
 * hoja impresa tal como se mando— y la revision siguiente nace como un
 * borrador editable. Con una sola fila habria que elegir entre perder lo que
 * se mando o no poder editar nunca mas.
 *
 * Las hermanas comparten numero y se distinguen por revision, asi que el
 * numero deja de ser unico por si solo: lo unico ahora es el par.
 *
 * Emitida es lo que antes se llamaba "tener fecha de emision": mientras
 * emitida_el este vacia la cotizacion es un borrador y se edita. Las 8.048 que
 * vinieron del Access ya estaban cerradas, asi que se dan por emitidas el dia
 * que se cotizaron —su propia fecha, que es lo mas cercano que sabemos— para
 * que el historial no aparezca entero como borradores editables.
 */
return new class extends Migration
{
    /**
     * Los estados que significan que la cotizacion ya salio. "Sin cotizar" no:
     * son consultas que nunca llegaron a tener precio, y quedar como borrador
     * es justo lo que permite cotizarlas ahora.
     */
    private function yaSalio(): array
    {
        return ['Confirmada', 'Vendida', Consulta::CERRADA, Consulta::VENCIDA];
    }

    public function up(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->unsignedInteger('revision')->default(0)->after('numero');
            // Apunta a la R0 de la familia. Vacio en la R0 misma.
            $tabla->foreignId('revision_de_id')->nullable()->after('revision')
                ->constrained('consultas')->nullOnDelete();
            $tabla->timestamp('emitida_el')->nullable()->after('revision_de_id');
            $tabla->foreignId('emitida_por')->nullable()->after('emitida_el')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->dropUnique('consultas_numero_unique');
            $tabla->unique(['numero', 'revision']);
        });

        // Lo que ya no era borrador, ya habia salido.
        DB::table('consultas')
            ->whereIn('estado', $this->yaSalio())
            ->whereNull('emitida_el')
            ->update(['emitida_el' => DB::raw('fecha')]);
    }

    public function down(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->dropUnique(['numero', 'revision']);
            $tabla->dropConstrainedForeignId('revision_de_id');
            $tabla->dropConstrainedForeignId('emitida_por');
            $tabla->dropColumn(['revision', 'emitida_el']);
            $tabla->unique('numero');
        });
    }
};
