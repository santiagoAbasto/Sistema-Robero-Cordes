<?php

namespace App\Console\Commands;

use App\Models\Consulta;
use App\Models\Empresa;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fusiona las empresas que tienen el nombre EXACTAMENTE igual (pedido de
 * Roberto, 30-10-2026).
 *
 * El sistema viejo guardaba cada ficha de contacto de una misma empresa como
 * una empresa aparte, y la importacion las trajo duplicadas (varias fichas con
 * el mismo nombre). Esto las une: elige la que sobrevive, le mueve TODO lo que cuelga
 * de las otras —contactos, razones sociales, enlaces, campos, relaciones,
 * cotizaciones (incluidas las de la papelera) e historial— y archiva las que
 * quedan vacias.
 *
 * No borra NADA. Las fusionadas quedan con activa=false (desaparecen del
 * listado y del conteo, que filtran por activas) y renombradas; se revierten
 * poniendo activa=true. Los datos que entran en conflicto se guardan en la
 * observacion de la que sobrevive, para no perderlos.
 *
 * Por defecto solo muestra el plan. Con --aplicar ejecuta, en UNA transaccion
 * y sobre una sola base nombrada. Antes de aplicar: respaldo (mysqldump).
 */
class FusionarEmpresasDuplicadas extends Command
{
    protected $signature = 'empresas:fusionar-duplicadas {--aplicar : Ejecuta la fusion; sin esto solo muestra el plan}';

    protected $description = 'Fusiona empresas con el nombre exactamente igual: mueve todo a una y archiva las demas';

    /** Datos de identidad que se completan en la que sobrevive si los tiene vacios. */
    private const ESCALARES = [
        'cuit', 'codigo_indice', 'codigo_isis', 'direccion', 'codigo_postal',
        'localidad_id', 'provincia_id', 'pais_id', 'rubro_id',
        'condicion_iva', 'condicion_pago',
    ];

    public function handle(): int
    {
        $base = DB::connection()->getDatabaseName();
        $aplicar = (bool) $this->option('aplicar');

        $this->line("Base de datos: <info>{$base}</info>");

        // Candidatas: SOLO activas. Se agrupan por nombre EXACTO (=== en PHP,
        // asi la colacion de MySQL no junta mayusculas o acentos distintos).
        $grupos = Empresa::query()->where('activa', true)
            ->orderBy('id')->get()
            ->groupBy(fn (Empresa $e) => $e->nombre)
            ->filter(fn (Collection $g) => $g->count() > 1);

        if ($grupos->isEmpty()) {
            $this->info('No hay empresas activas con el nombre exactamente igual. Nada para fusionar.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($aplicar
            ? '<comment>APLICANDO la fusion…</comment>'
            : '<comment>VISTA PREVIA — no se toca nada. Con --aplicar se ejecuta.</comment>');
        $this->newLine();

        // Plan por grupo (se arma una vez y se reusa al aplicar).
        $plan = [];
        foreach ($grupos as $nombre => $empresas) {
            $sobrevive = $this->elegirSobreviviente($empresas);
            $duplicadas = $empresas->reject(fn (Empresa $e) => $e->id === $sobrevive->id)->values();
            $plan[] = compact('nombre', 'sobrevive', 'duplicadas');

            $this->line("<info>{$nombre}</info> ({$empresas->count()} fichas)");
            foreach ($empresas as $e) {
                $marca = $e->id === $sobrevive->id
                    ? '  <comment>← SOBREVIVE</comment>'
                    : "  → se archiva, todo pasa a #{$sobrevive->id}";
                $this->line(sprintf(
                    '   #%-8d cotizaciones=%-4d contactos=%-3d razones=%-2d%s',
                    $e->id, $this->contarConsultas($e->id), $e->contactos()->count(),
                    $e->razonesSociales()->count(), $marca,
                ));
            }
            $this->newLine();
        }

        $archivar = collect($plan)->sum(fn ($p) => $p['duplicadas']->count());
        $this->line(sprintf('Grupos con duplicados: <info>%d</info> · empresas a archivar: <info>%d</info>', count($plan), $archivar));

        if (! $aplicar) {
            $this->newLine();
            $this->info('Vista previa lista. Para ejecutarla: php artisan empresas:fusionar-duplicadas --aplicar');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn("Vas a fusionar en la base '{$base}': se mueven datos y se archivan {$archivar} empresas.");
        $this->warn('Tiene que existir un respaldo (mysqldump) de esta base antes de seguir.');
        if (! $this->confirm('¿Hiciste el respaldo y querés aplicar la fusión ahora?', false)) {
            $this->info('Cancelado. No se tocó nada.');

            return self::SUCCESS;
        }

        $r = ['grupos' => 0, 'archivadas' => 0, 'consultas' => 0, 'contactos' => 0, 'razones' => 0, 'enlaces' => 0, 'campos' => 0];
        $fallidos = [];

        // Una transaccion POR GRUPO: cada empresa queda consistente, es
        // reanudable (al re-correr, las ya archivadas quedan inactivas y
        // renombradas, asi que no se vuelven a tomar) y un grupo con problema
        // no frena los demas.
        foreach ($plan as $i => $p) {
            try {
                DB::transaction(function () use ($p, &$r) {
                    $this->fusionarGrupo($p['sobrevive'], $p['duplicadas'], $r);
                });
                $r['grupos']++;
            } catch (\Throwable $e) {
                $fallidos[] = "{$p['nombre']}: ".$e->getMessage();
            }

            if (($i + 1) % 50 === 0) {
                $this->line('  … '.($i + 1).'/'.count($plan).' grupos procesados');
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Listo. Grupos: %d · archivadas: %d · cotizaciones movidas: %d · contactos: %d · razones: %d · enlaces: %d · campos: %d',
            $r['grupos'], $r['archivadas'], $r['consultas'], $r['contactos'], $r['razones'], $r['enlaces'], $r['campos'],
        ));
        $this->line('Las archivadas quedaron con activa=false (fuera del listado y del conteo) y renombradas. Se revierten con activa=true.');

        if ($fallidos !== []) {
            $this->newLine();
            $this->warn('Grupos que NO se fusionaron (quedaron intactos, se pueden reintentar):');
            foreach ($fallidos as $f) {
                $this->line("  - {$f}");
            }
        }

        return self::SUCCESS;
    }

    /** La que mas cotizaciones tiene (incluida la papelera); desempata la mas vieja. */
    private function elegirSobreviviente(Collection $empresas): Empresa
    {
        $conteos = $empresas->mapWithKeys(fn (Empresa $e) => [$e->id => $this->contarConsultas($e->id)]);

        return $empresas->sort(fn (Empresa $a, Empresa $b) => ($conteos[$b->id] <=> $conteos[$a->id]) ?: ($a->id <=> $b->id))->first();
    }

    private function contarConsultas(int $empresaId): int
    {
        return Consulta::withTrashed()->where('empresa_id', $empresaId)->count();
    }

    private function fusionarGrupo(Empresa $sobrevive, Collection $duplicadas, array &$r): void
    {
        foreach ($duplicadas as $dup) {
            // 1) Completar los datos vacios de la que sobrevive, sin pisar; lo
            //    que entra en conflicto o sobra va a la observacion, no se pierde.
            $this->traspasarEscalares($sobrevive, $dup);

            // 2) Mover lo que cuelga. Updates crudos a proposito: mueven tambien
            //    las cotizaciones de la papelera (soft-deleted) y no disparan el
            //    historial por cada fila.
            $r['consultas'] += DB::table('consultas')->where('empresa_id', $dup->id)->update(['empresa_id' => $sobrevive->id]);
            $r['contactos'] += DB::table('contactos')->where('empresa_id', $dup->id)->update(['empresa_id' => $sobrevive->id]);
            $r['razones'] += DB::table('razones_sociales')->where('empresa_id', $dup->id)->update(['empresa_id' => $sobrevive->id]);
            $r['enlaces'] += DB::table('empresa_enlaces')->where('empresa_id', $dup->id)->update(['empresa_id' => $sobrevive->id]);
            $r['campos'] += DB::table('empresa_campos')->where('empresa_id', $dup->id)->update(['empresa_id' => $sobrevive->id]);
            DB::table('historial_cambios')->where('empresa_id', $dup->id)->update(['empresa_id' => $sobrevive->id]);

            // 3) Relaciones: hay unique(empresa_id, relacion). La que la que
            //    sobrevive ya tiene no se puede mover (chocaria el unique); se
            //    descarta la de la duplicada —la relacion igual queda en la que
            //    sobrevive, no se pierde informacion.
            $yaTiene = DB::table('empresa_relacion')->where('empresa_id', $sobrevive->id)->pluck('relacion')->all();
            foreach (DB::table('empresa_relacion')->where('empresa_id', $dup->id)->get() as $rel) {
                if (in_array($rel->relacion, $yaTiene, true)) {
                    DB::table('empresa_relacion')->where('id', $rel->id)->delete();
                } else {
                    DB::table('empresa_relacion')->where('id', $rel->id)->update(['empresa_id' => $sobrevive->id]);
                    $yaTiene[] = $rel->relacion;
                }
            }

            // 4) Archivar la duplicada ya vacia. NO se borra: queda reversible.
            DB::table('empresas')->where('id', $dup->id)->update([
                'activa' => false,
                'nombre' => mb_substr($dup->nombre." (fusionada en #{$sobrevive->id})", 0, 150),
                'updated_at' => Carbon::now(),
            ]);
            $r['archivadas']++;
        }

        // 5) Que quede un solo principal y una sola habitual en la que sobrevive.
        $this->dejarUnoSolo('contactos', $sobrevive->id, 'principal');
        $this->dejarUnoSolo('razones_sociales', $sobrevive->id, 'habitual');
    }

    /** Completa escalares vacios en la que sobrevive; lo que choca o sobra va a la observacion. */
    private function traspasarEscalares(Empresa $sobrevive, Empresa $dup): void
    {
        $extra = [];
        foreach (self::ESCALARES as $campo) {
            $valDup = $dup->$campo;
            if ($valDup === null || $valDup === '') {
                continue;
            }
            if ($sobrevive->$campo === null || $sobrevive->$campo === '') {
                $sobrevive->$campo = $valDup;
            } elseif ((string) $sobrevive->$campo !== (string) $valDup) {
                $extra[] = "{$campo}={$valDup}";
            }
        }

        $notas = [];
        $obsDup = trim((string) $dup->observacion_general);
        if ($obsDup !== '' && $obsDup !== trim((string) $sobrevive->observacion_general)) {
            $notas[] = $obsDup;
        }
        if ($extra !== []) {
            $notas[] = 'Datos: '.implode('; ', $extra);
        }
        if ($notas !== []) {
            $marca = "[Ficha fusionada #{$dup->id}] ".implode(' · ', $notas);
            $sobrevive->observacion_general = trim(((string) $sobrevive->observacion_general)."\n".$marca);
        }

        $sobrevive->save();
    }

    /** Deja el flag en TRUE solo en la fila mas vieja; el resto en FALSE. */
    private function dejarUnoSolo(string $tabla, int $empresaId, string $flag): void
    {
        $conFlag = DB::table($tabla)->where('empresa_id', $empresaId)->where($flag, true)->orderBy('id')->pluck('id');
        if ($conFlag->count() <= 1) {
            return;
        }
        DB::table($tabla)->whereIn('id', $conFlag->slice(1)->all())->update([$flag => false]);
    }
}
