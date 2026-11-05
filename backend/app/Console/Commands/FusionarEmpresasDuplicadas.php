<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Services\FusionDeEmpresas;
use Illuminate\Console\Command;
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

    private FusionDeEmpresas $fusion;

    public function handle(): int
    {
        $this->fusion = new FusionDeEmpresas;

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
            $sobrevive = $this->fusion->elegirSobreviviente($empresas);
            $duplicadas = $empresas->reject(fn (Empresa $e) => $e->id === $sobrevive->id)->values();
            $plan[] = compact('nombre', 'sobrevive', 'duplicadas');

            $this->line("<info>{$nombre}</info> ({$empresas->count()} fichas)");
            foreach ($empresas as $e) {
                $marca = $e->id === $sobrevive->id
                    ? '  <comment>← SOBREVIVE</comment>'
                    : "  → se archiva, todo pasa a #{$sobrevive->id}";
                $this->line(sprintf(
                    '   #%-8d cotizaciones=%-4d contactos=%-3d razones=%-2d%s',
                    $e->id, $this->fusion->contarConsultas($e->id), $e->contactos()->count(),
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
                $parcial = DB::transaction(
                    fn () => $this->fusion->fusionar($p['sobrevive'], $p['duplicadas']),
                );

                foreach ($parcial as $clave => $cuanto) {
                    $r[$clave] += $cuanto;
                }

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
}
