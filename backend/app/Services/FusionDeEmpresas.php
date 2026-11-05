<?php

namespace App\Services;

use App\Models\Consulta;
use App\Models\Empresa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Combinar dos fichas de una misma empresa en una sola.
 *
 * Pedido de Roberto (04-11-2026): buscando un cliente salían
 * dos fichas, porque el nombre de antes de migrar no era exactamente igual
 * ("…ELECTRICAS S" y "…ELECTRICAS SRL"). El comando empresas:fusionar-duplicadas
 * sólo junta nombres idénticos; esto es lo mismo, para que lo haga una persona
 * desde la ficha cuando ve dos que son una.
 *
 * Se elige la ficha que QUEDA. A esa se le mueve TODO lo que cuelga de la otra
 * —contactos, razones sociales, enlaces, condiciones de trabajo, relaciones,
 * cotizaciones (también las de la papelera) e historial— y la otra se ARCHIVA:
 * no se borra nada. Los datos de identidad vacíos de la que queda se completan
 * con los de la otra, sin pisar; lo que choca queda anotado en la observación.
 *
 * Lo usan el comando y la pantalla. Quien lo llame decide la transacción.
 */
class FusionDeEmpresas
{
    /** Datos de identidad que se completan en la que queda si los tiene vacíos. */
    private const ESCALARES = [
        'cuit', 'codigo_indice', 'codigo_isis', 'direccion', 'codigo_postal',
        'localidad_id', 'provincia_id', 'pais_id', 'rubro_id',
        'condicion_iva', 'condicion_pago',
    ];

    /**
     * Lo que sobra al final de un nombre y no lo distingue: "S.A.", "SRL" y las
     * letras sueltas que quedan cuando el nombre viejo está cortado ("… S").
     */
    private const LEGALES = [
        's', 'a', 'r', 'l', 'sa', 'srl', 'sas', 'sca', 'sh', 'ltda', 'cia',
        'sociedad', 'anonima', 'limitada', 'responsabilidad',
    ];

    /**
     * El nombre "pelado", para saber si dos fichas hablan de la misma empresa:
     * sin tildes, sin signos, en minúsculas y sin la forma legal del final.
     */
    public static function clave(string $nombre): string
    {
        $t = Str::of($nombre)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
        $palabras = $t === '' ? [] : explode(' ', $t);

        while ($palabras !== [] && in_array(end($palabras), self::LEGALES, true)) {
            array_pop($palabras);
        }

        return implode(' ', $palabras);
    }

    /**
     * ¿Dos nombres pelados son la misma empresa? Iguales, o uno es el comienzo
     * del otro con al menos dos palabras ("ALFA RESISTENCIAS" y "ALFA
     * RESISTENCIAS ELECTRICAS"). Una sola palabra no alcanza: "BANCO" no es
     * "BANCO NACION".
     */
    public static function sonLaMisma(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        [$corta, $larga] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];

        return str_contains($corta, ' ') && str_starts_with($larga, $corta.' ');
    }

    /**
     * Las fichas activas que parecen ser la misma empresa, las de más
     * cotizaciones primero. Es una SUGERENCIA: quien combina las mira antes.
     *
     * @return Collection<int, Empresa>
     */
    public function parecidas(Empresa $empresa, int $limite = 8): Collection
    {
        $clave = self::clave($empresa->nombre);

        if ($clave === '') {
            return collect();
        }

        $primera = explode(' ', $clave)[0];

        return Empresa::query()
            ->where('activa', true)
            ->where('id', '!=', $empresa->id)
            ->where('nombre', 'like', $primera.'%')
            ->get()
            ->filter(fn (Empresa $o) => self::sonLaMisma($clave, self::clave($o->nombre)))
            ->sortByDesc(fn (Empresa $o) => $this->contarConsultas($o->id))
            ->take($limite)
            ->values();
    }

    /**
     * Lo que hay en una ficha, para compararla con otra antes de combinarlas.
     *
     * @return array<string, mixed>
     */
    public function resumen(Empresa $empresa): array
    {
        $consultas = Consulta::query()->where('empresa_id', $empresa->id);

        return [
            'id' => $empresa->id,
            'nombre' => $empresa->nombre,
            'cuit' => $empresa->cuit,
            'direccion' => $empresa->direccion,
            'localidad' => $empresa->localidad?->nombre,
            'activa' => (bool) $empresa->activa,
            // Las que se ven en la ficha: sin borradores ni papelera.
            'cotizaciones' => (clone $consultas)->where('tipo', 'Cotizacion')->where('estado', '!=', 'Borrador')->count(),
            'consultas' => (clone $consultas)->count(),
            'ultima' => (clone $consultas)->max('fecha'),
            'contactos' => $empresa->contactos()->count(),
            'razones_sociales' => $empresa->razonesSociales()->count(),
        ];
    }

    /** La que más cotizaciones tiene (incluida la papelera); desempata la más vieja. */
    public function elegirSobreviviente(Collection $empresas): Empresa
    {
        $conteos = $empresas->mapWithKeys(fn (Empresa $e) => [$e->id => $this->contarConsultas($e->id)]);

        return $empresas
            ->sort(fn (Empresa $a, Empresa $b) => ($conteos[$b->id] <=> $conteos[$a->id]) ?: ($a->id <=> $b->id))
            ->first();
    }

    public function contarConsultas(int $empresaId): int
    {
        return Consulta::withTrashed()->where('empresa_id', $empresaId)->count();
    }

    /**
     * Pasa todo lo de las duplicadas a la que queda y las archiva.
     *
     * @param  Collection<int, Empresa>  $duplicadas
     * @return array{archivadas: int, consultas: int, contactos: int, razones: int, enlaces: int, campos: int}
     */
    public function fusionar(Empresa $sobrevive, Collection $duplicadas): array
    {
        $r = ['archivadas' => 0, 'consultas' => 0, 'contactos' => 0, 'razones' => 0, 'enlaces' => 0, 'campos' => 0];

        foreach ($duplicadas as $dup) {
            $antes = $r;

            // 1) Completar los datos vacíos de la que queda, sin pisar; lo que
            //    entra en conflicto o sobra va a la observación, no se pierde.
            $this->traspasarEscalares($sobrevive, $dup);

            // 2) Mover lo que cuelga. Updates crudos a propósito: mueven también
            //    las cotizaciones de la papelera (soft-deleted) y no disparan el
            //    historial por cada fila.
            $r['consultas'] += DB::table('consultas')->where('empresa_id', $dup->id)->update(['empresa_id' => $sobrevive->id]);
            $r['contactos'] += DB::table('contactos')->where('empresa_id', $dup->id)->update(['empresa_id' => $sobrevive->id]);
            $r['razones'] += DB::table('razones_sociales')->where('empresa_id', $dup->id)->update(['empresa_id' => $sobrevive->id]);
            $r['enlaces'] += DB::table('empresa_enlaces')->where('empresa_id', $dup->id)->update(['empresa_id' => $sobrevive->id]);
            $r['campos'] += DB::table('empresa_campos')->where('empresa_id', $dup->id)->update(['empresa_id' => $sobrevive->id]);
            DB::table('historial_cambios')->where('empresa_id', $dup->id)->update(['empresa_id' => $sobrevive->id]);

            // 3) Relaciones: hay unique(empresa_id, relacion). La que la que
            //    queda ya tiene no se puede mover (chocaría el unique); se
            //    descarta la de la duplicada —la relación igual queda en la que
            //    queda, no se pierde información.
            $yaTiene = DB::table('empresa_relacion')->where('empresa_id', $sobrevive->id)->pluck('relacion')->all();
            foreach (DB::table('empresa_relacion')->where('empresa_id', $dup->id)->get() as $rel) {
                if (in_array($rel->relacion, $yaTiene, true)) {
                    DB::table('empresa_relacion')->where('id', $rel->id)->delete();
                } else {
                    DB::table('empresa_relacion')->where('id', $rel->id)->update(['empresa_id' => $sobrevive->id]);
                    $yaTiene[] = $rel->relacion;
                }
            }

            // 4) Archivar la duplicada ya vacía. NO se borra: queda reversible.
            $nombreDeAntes = $dup->nombre;
            DB::table('empresas')->where('id', $dup->id)->update([
                'activa' => false,
                'nombre' => mb_substr($dup->nombre." (fusionada en #{$sobrevive->id})", 0, 150),
                'updated_at' => Carbon::now(),
            ]);
            $r['archivadas']++;

            // 5) Constancia en el historial de la que queda: qué ficha se sumó y
            //    cuánto trajo. Sin esto, "Ver cambios" no explicaría por qué
            //    aparecieron de golpe tantas cotizaciones.
            $sobrevive->anotarCambio(
                'Modificacion',
                'fusion',
                "{$nombreDeAntes} (#{$dup->id})",
                sprintf(
                    'Se combinó con esta ficha: %d consultas, %d contactos, %d razones sociales.',
                    $r['consultas'] - $antes['consultas'],
                    $r['contactos'] - $antes['contactos'],
                    $r['razones'] - $antes['razones'],
                ),
            );
        }

        // 6) Que quede un solo principal y una sola habitual en la que queda.
        $this->dejarUnoSolo('contactos', $sobrevive->id, 'principal');
        $this->dejarUnoSolo('razones_sociales', $sobrevive->id, 'habitual');

        return $r;
    }

    /** Completa escalares vacíos en la que queda; lo que choca o sobra va a la observación. */
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

    /** Deja el flag en TRUE solo en la fila más vieja; el resto en FALSE. */
    private function dejarUnoSolo(string $tabla, int $empresaId, string $flag): void
    {
        $conFlag = DB::table($tabla)->where('empresa_id', $empresaId)->where($flag, true)->orderBy('id')->pluck('id');

        if ($conFlag->count() <= 1) {
            return;
        }

        DB::table($tabla)->whereIn('id', $conFlag->slice(1)->all())->update([$flag => false]);
    }
}
