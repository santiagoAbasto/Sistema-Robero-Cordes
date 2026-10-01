<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las localidades que se perdieron con la limpieza de provincias del 26-09.
 *
 * 281 fichas del indice traian localidad y no provincia. El importador las
 * colgaba de una provincia "Sin determinar" para no perderlas; la limpieza
 * borro esa provincia por no ser una provincia, y con ella las localidades:
 * las fichas quedaron sin localidad.
 *
 * Se recuperan del indice viejo, que esta guardado en la base:
 *  - si el nombre coincide con una sola localidad cargada, se le pone esa, y
 *    su provincia si la ficha no tenia ("LANUS" esta en Buenos Aires);
 *  - si no coincide con ninguna, o con varias ("CAPITAL"), no se adivina: el
 *    nombre queda escrito en la observacion general para elegirla a mano.
 *
 * Solo toca fichas que siguen sin localidad: lo que alguien ya cargo a mano
 * no se pisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        $plano = fn (string $t) => preg_replace('/[^a-z0-9]/', '', strtr(
            mb_strtolower($t),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u'],
        )) ?? '';

        $localidades = DB::table('localidades')->get(['id', 'nombre', 'provincia_id'])
            ->groupBy(fn ($l) => $plano($l->nombre));

        $filas = DB::table('migracion_originales')->where('archivo', 'empresas.csv')->get(['datos']);

        foreach ($filas as $fila) {
            $d = json_decode($fila->datos, true) ?: [];
            $nombre = trim((string) ($d['LOCALIDAD'] ?? ''));

            // Solo las que el importador colgaba de "Sin determinar".
            if ($nombre === '' || trim((string) ($d['PROVINCIA'] ?? '')) !== '') {
                continue;
            }

            $empresa = DB::table('empresas')
                ->where('codigo_indice', (string) ($d['ID'] ?? ''))
                ->whereNull('localidad_id')
                ->first(['id', 'provincia_id', 'observacion_general']);

            if (! $empresa) {
                continue;
            }

            $coinciden = $localidades->get($plano($nombre), collect());

            if ($coinciden->count() === 1) {
                $localidad = $coinciden->first();

                DB::table('empresas')->where('id', $empresa->id)->update([
                    'localidad_id' => $localidad->id,
                    'provincia_id' => $empresa->provincia_id ?? $localidad->provincia_id,
                ]);

                continue;
            }

            $nota = "Localidad en el indice viejo: {$nombre} (falta elegirla en Modificar).";

            if (! str_contains((string) $empresa->observacion_general, $nota)) {
                DB::table('empresas')->where('id', $empresa->id)->update([
                    'observacion_general' => trim($nota."\n".$empresa->observacion_general),
                ]);
            }
        }
    }

    public function down(): void
    {
        // No se vuelve atras: seria volver a perder las localidades.
    }
};
