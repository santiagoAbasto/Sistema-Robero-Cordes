<?php

namespace App\Services;

use App\Models\CondicionHabitual;
use App\Models\CondicionPago;
use App\Models\EmpresaCampo;
use App\Models\OpcionDeCondicion;
use App\Models\TipoDeCondicion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La lista de condiciones de trabajo de la ficha ("Tipo de pago", "Flete", "Cómo lo retira") y los
 * datos de cada una. Es la única puerta: siembra la lista con lo que ya está cargado, agrega datos
 * sin repetirlos, y renombra o une propagando el cambio a las fichas.
 *
 * LA FICHA GUARDA TEXTO Y TAMBIÉN EL VÍNCULO. Cada campo de una ficha (EmpresaCampo) sigue teniendo
 * su título y su dato escritos —de ahí sale la cotización, y no hay que tocar nada de eso—, y además
 * apunta al título y al dato de la lista. Cuando algo de la lista cambia, se actualiza el texto de
 * todas las fichas que lo usan: la lista es la única fuente.
 *
 * Nunca inventa datos: todo lo que siembra ya existía en las fichas, en los juegos de condiciones o
 * en la lista de condiciones de pago.
 */
final class CatalogoDeCondiciones
{
    /** Los títulos que ya alimentan la cabecera de la cotización (CondicionesDeLaFicha::DE_LA_CABECERA). */
    public const DE_LA_CABECERA = ['Tipo de pago', 'Lista de precios', 'Moneda habitual'];

    /** Las condiciones que son de pago (ya normalizadas): sus datos son las condiciones de pago de siempre. */
    private const DE_PAGO = ['condic de pago', 'condiciones de pago', 'condicion de pago', 'tipo de pago'];

    /** "Condic. de Pago" y "condic de pago" son el mismo título; "30 días" y "30 dias" el mismo dato. */
    public static function clave(string $texto): string
    {
        return CondicionesDeLaFicha::normalizar($texto);
    }

    /** Sin espacios de más, y en una sola línea. */
    public static function limpiar(string $texto): string
    {
        return trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
    }

    /* ----------------------------------------------------------------- siembra */

    /**
     * Arma la lista con lo que ya está cargado. Se puede correr cuantas veces haga falta: sólo agrega
     * lo que falta y vincula lo que no está vinculado.
     *
     * Un dato entra a la lista si se repite ($minimo veces o más): lo que se escribió una sola vez
     * queda en la ficha como estaba, "fuera de la lista", hasta que alguien decida si vale.
     *
     * Sin $aplicar no escribe nada: cuenta lo que haría.
     *
     * @return array{aplicado: bool, tipos_nuevos: list<string>, opciones_nuevas: int, vinculados: int, sueltos: int, datos_sueltos: int, muestra_de_sueltos: list<array{titulo: string, valor: string, n: int}>}
     */
    public function sembrar(bool $aplicar = true, int $minimo = 2): array
    {
        DB::beginTransaction();

        try {
            $reporte = $this->sembrarAhora(max(1, $minimo));
            $aplicar ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return ['aplicado' => $aplicar] + $reporte;
    }

    /** @return array{tipos_nuevos: list<string>, opciones_nuevas: int, vinculados: int, sueltos: int, datos_sueltos: int, muestra_de_sueltos: list<array{titulo: string, valor: string, n: int}>} */
    private function sembrarAhora(int $minimo): array
    {
        // 1. Cómo se escribe cada título y cada dato en las fichas, y cuántas veces.
        $titulos = [];
        $datos = [];

        DB::table('empresa_campos')->select('id', 'titulo', 'valor')->orderBy('id')->chunkById(1000, function ($filas) use (&$titulos, &$datos) {
            foreach ($filas as $f) {
                $kt = self::clave((string) $f->titulo);
                if ($kt === '') {
                    continue;
                }

                $t = self::limpiar((string) $f->titulo);
                $titulos[$kt][$t] = ($titulos[$kt][$t] ?? 0) + 1;

                $v = self::limpiar((string) $f->valor);
                if ($v !== '' && mb_strlen($v) <= 255 && ($kv = self::clave($v)) !== '') {
                    $datos[$kt][$kv][$v] = ($datos[$kt][$kv][$v] ?? 0) + 1;
                }
            }
        });

        // 2. Los títulos. Manda la ortografía de la cabecera y de los juegos de condiciones (es la de la
        // cotización); después, la que más se usa en las fichas.
        $nombres = [];
        foreach (self::DE_LA_CABECERA as $t) {
            $nombres[self::clave($t)] ??= $t;
        }
        foreach (CondicionHabitual::query()->orderBy('orden')->pluck('titulo') as $t) {
            $t = self::limpiar((string) $t);
            if ($t !== '' && mb_strlen($t) <= 60) {
                $nombres[self::clave($t)] ??= $t;
            }
        }
        foreach ($titulos as $kt => $ortografias) {
            $nombres[$kt] ??= mb_substr(self::masUsada($ortografias), 0, 60);
        }

        $existentes = TipoDeCondicion::all()->keyBy(fn (TipoDeCondicion $t) => self::clave($t->nombre));
        $orden = (int) TipoDeCondicion::max('orden');
        $nuevos = [];

        foreach ($nombres as $kt => $nombre) {
            if (! $existentes->has($kt)) {
                $existentes[$kt] = TipoDeCondicion::create(['nombre' => $nombre, 'orden' => ++$orden]);
                $nuevos[] = $nombre;
            }
        }

        // 3. Los datos que se repiten, y las condiciones de pago de siempre para las condiciones de pago.
        $opciones = [];
        foreach (OpcionDeCondicion::all() as $o) {
            $opciones[$o->tipo_de_condicion_id][self::clave($o->texto)] = $o;
        }
        $nuevas = 0;
        $agregar = function (TipoDeCondicion $tipo, string $texto) use (&$opciones, &$nuevas): void {
            $k = self::clave($texto);
            if ($k === '' || isset($opciones[$tipo->id][$k])) {
                return;
            }
            $opciones[$tipo->id][$k] = OpcionDeCondicion::create([
                'tipo_de_condicion_id' => $tipo->id,
                'texto' => $texto,
                'orden' => count($opciones[$tipo->id] ?? []) + 1,
            ]);
            $nuevas++;
        };

        foreach ($datos as $kt => $valores) {
            foreach ($valores as $ortografias) {
                if (array_sum($ortografias) >= $minimo) {
                    $agregar($existentes[$kt], self::masUsada($ortografias));
                }
            }
        }
        foreach ($existentes as $kt => $tipo) {
            if (in_array($kt, self::DE_PAGO, true)) {
                foreach (CondicionPago::where('activo', true)->orderBy('orden')->pluck('nombre') as $nombre) {
                    $agregar($tipo, self::limpiar($nombre));
                }
            }
        }

        // 4. Vincular cada campo con su título y su dato, y contar lo que queda suelto.
        $vinculados = 0;
        $sueltos = 0;
        $pares = [];

        DB::table('empresa_campos')->select('id', 'titulo', 'valor', 'tipo_de_condicion_id', 'opcion_de_condicion_id')
            ->orderBy('id')->chunkById(500, function ($filas) use ($existentes, $opciones, &$vinculados, &$sueltos, &$pares) {
                foreach ($filas as $f) {
                    $tipo = $existentes[self::clave((string) $f->titulo)] ?? null;
                    if ($tipo === null) {
                        continue;
                    }

                    $valor = self::limpiar((string) $f->valor);
                    $opcion = $valor !== '' ? ($opciones[$tipo->id][self::clave($valor)] ?? null) : null;

                    $tipoActual = $f->tipo_de_condicion_id === null ? null : (int) $f->tipo_de_condicion_id;
                    $opcionActual = $f->opcion_de_condicion_id === null ? null : (int) $f->opcion_de_condicion_id;

                    if ($tipoActual !== $tipo->id || $opcionActual !== $opcion?->id) {
                        DB::table('empresa_campos')->where('id', $f->id)->update([
                            'tipo_de_condicion_id' => $tipo->id,
                            'opcion_de_condicion_id' => $opcion?->id,
                        ]);
                    }

                    if ($opcion !== null) {
                        $vinculados++;
                    } elseif ($valor !== '') {
                        $sueltos++;
                        $par = $tipo->nombre."\0".$valor;
                        $pares[$par] = ($pares[$par] ?? 0) + 1;
                    }
                }
            });

        arsort($pares);

        return [
            'tipos_nuevos' => $nuevos,
            'opciones_nuevas' => $nuevas,
            'vinculados' => $vinculados,
            'sueltos' => $sueltos,
            'datos_sueltos' => count($pares),
            'muestra_de_sueltos' => collect($pares)->take(15)->map(function ($n, $par) {
                [$titulo, $valor] = explode("\0", (string) $par, 2);

                return ['titulo' => $titulo, 'valor' => $valor, 'n' => $n];
            })->values()->all(),
        ];
    }

    /**
     * De "Flete" (30), "flete" (2) y "FLETE" (1), el primero: la ortografía que más se usa. Si empatan,
     * la que está escrita como un título (mayúscula y minúsculas) antes que toda en mayúsculas o en minúsculas.
     *
     * @param  array<string, int>  $ortografias  cómo está escrito => cuántas veces
     */
    private static function masUsada(array $ortografias): string
    {
        $mixta = fn (string $t) => preg_match('/\p{Lu}/u', $t) && preg_match('/\p{Ll}/u', $t);

        uksort($ortografias, fn ($a, $b) => [$ortografias[$b], $mixta($b) ? 1 : 0, $a] <=> [$ortografias[$a], $mixta($a) ? 1 : 0, $b]);

        return (string) array_key_first($ortografias);
    }

    /* -------------------------------------------------------------- los datos */

    /**
     * El dato de una condición: el que ya está, o uno nuevo. "A cargo del cliente" y "a cargo del cliente "
     * son el mismo: no se repite. Si estaba desactivado, vuelve a estar disponible.
     */
    public function opcion(TipoDeCondicion $tipo, string $texto): OpcionDeCondicion
    {
        $texto = self::limpiar($texto);
        $k = self::clave($texto);

        if ($k === '') {
            throw ValidationException::withMessages(['opcion_nueva' => 'Falta escribir el dato.']);
        }

        $existente = $tipo->opciones()->get()->first(fn (OpcionDeCondicion $o) => self::clave($o->texto) === $k);

        if ($existente !== null) {
            $existente->activo || $existente->update(['activo' => true]);

            return $existente;
        }

        return $tipo->opciones()->create(['texto' => $texto, 'orden' => (int) $tipo->opciones()->max('orden') + 1]);
    }

    /** Un título nuevo para la lista (o el que ya estaba, si es el mismo escrito distinto). */
    public function tipo(string $nombre): TipoDeCondicion
    {
        $nombre = self::limpiar($nombre);
        $k = self::clave($nombre);

        if ($k === '') {
            throw ValidationException::withMessages(['nombre' => 'Falta el nombre de la condición.']);
        }

        $existente = TipoDeCondicion::all()->first(fn (TipoDeCondicion $t) => self::clave($t->nombre) === $k);

        if ($existente !== null) {
            $existente->activo || $existente->update(['activo' => true]);

            return $existente;
        }

        return TipoDeCondicion::create(['nombre' => mb_substr($nombre, 0, 60), 'orden' => (int) TipoDeCondicion::max('orden') + 1]);
    }

    /**
     * Un dato que figura en fichas pero no en la lista: se lo agrega a la lista (con su título, si
     * hace falta) y se vinculan todas las fichas que lo tenían escrito igual.
     *
     * @return array{opcion: OpcionDeCondicion, fichas: int}
     */
    public function adoptar(string $titulo, string $valor): array
    {
        $tipo = $this->tipo($titulo);
        $opcion = $this->opcion($tipo, $valor);
        $kt = self::clave($titulo);
        $kv = self::clave($valor);
        $fichas = 0;

        EmpresaCampo::whereNull('opcion_de_condicion_id')->get()
            ->filter(fn (EmpresaCampo $c) => self::clave((string) $c->titulo) === $kt && self::clave((string) $c->valor) === $kv)
            ->each(function (EmpresaCampo $c) use ($tipo, $opcion, &$fichas) {
                $c->update(['titulo' => $tipo->nombre, 'valor' => $opcion->texto, 'tipo_de_condicion_id' => $tipo->id, 'opcion_de_condicion_id' => $opcion->id]);
                $fichas++;
            });

        return ['opcion' => $opcion, 'fichas' => $fichas];
    }

    /**
     * Los datos cargados en fichas que no están en la lista, del más repetido al menos.
     *
     * @return list<array{titulo: string, valor: string, n: int}>
     */
    public function sueltos(int $limite = 200): array
    {
        return DB::table('empresa_campos')
            ->whereNull('opcion_de_condicion_id')->whereNotNull('valor')->where('valor', '!=', '')
            ->selectRaw('titulo, valor, count(*) as n')->groupBy('titulo', 'valor')
            ->orderByDesc('n')->orderBy('titulo')->limit($limite)->get()
            ->map(fn ($f) => ['titulo' => (string) $f->titulo, 'valor' => (string) $f->valor, 'n' => (int) $f->n])->all();
    }

    /* ------------------------------------------------- cambiar lo que ya existe */

    /** Cambia cómo se escribe un dato —en la lista y en todas las fichas que lo usan—. */
    public function renombrarOpcion(OpcionDeCondicion $opcion, string $texto): OpcionDeCondicion
    {
        $texto = self::limpiar($texto);
        $this->sinRepetirOpcion($opcion->tipo_de_condicion_id, $texto, $opcion->id);

        $opcion->update(['texto' => $texto]);
        $opcion->campos()->get()->each(fn (EmpresaCampo $c) => $c->update(['valor' => $texto]));

        return $opcion->fresh();
    }

    /** Cambia cómo se llama una condición —en la lista y en el título de todas las fichas que la usan—. */
    public function renombrarTipo(TipoDeCondicion $tipo, string $nombre): TipoDeCondicion
    {
        $nombre = mb_substr(self::limpiar($nombre), 0, 60);
        $k = self::clave($nombre);

        if ($k === '' || TipoDeCondicion::where('id', '!=', $tipo->id)->get()->contains(fn (TipoDeCondicion $t) => self::clave($t->nombre) === $k)) {
            throw ValidationException::withMessages(['nombre' => $k === '' ? 'Falta el nombre.' : 'Ya está en la lista.']);
        }

        $tipo->update(['nombre' => $nombre]);
        $tipo->campos()->get()->each(fn (EmpresaCampo $c) => $c->update(['titulo' => $nombre]));

        return $tipo->fresh();
    }

    /** Dos datos eran el mismo: las fichas del primero pasan al segundo y el primero se borra. */
    public function unirOpciones(OpcionDeCondicion $origen, OpcionDeCondicion $destino): int
    {
        if ($origen->id === $destino->id) {
            throw ValidationException::withMessages(['con' => 'Elegí otro dato para unir.']);
        }

        $tipo = $destino->tipo;

        return DB::transaction(function () use ($origen, $destino, $tipo) {
            $fichas = 0;

            $origen->campos()->get()->each(function (EmpresaCampo $c) use ($destino, $tipo, &$fichas) {
                $c->update([
                    'titulo' => $tipo->nombre, 'valor' => $destino->texto,
                    'tipo_de_condicion_id' => $tipo->id, 'opcion_de_condicion_id' => $destino->id,
                ]);
                $fichas++;
            });

            $origen->delete();

            return $fichas;
        });
    }

    /** Dos condiciones eran la misma ("Flete" y "Fletes"): todo pasa a la segunda y la primera se borra. */
    public function unirTipos(TipoDeCondicion $origen, TipoDeCondicion $destino): int
    {
        if ($origen->id === $destino->id) {
            throw ValidationException::withMessages(['con' => 'Elegí otra condición para unir.']);
        }

        return DB::transaction(function () use ($origen, $destino) {
            $fichas = 0;
            $enDestino = $destino->opciones()->get()->keyBy(fn (OpcionDeCondicion $o) => self::clave($o->texto));

            foreach ($origen->opciones()->get() as $opcion) {
                $igual = $enDestino->get(self::clave($opcion->texto));

                if ($igual !== null) {
                    $fichas += $this->unirOpciones($opcion, $igual);

                    continue;
                }

                $opcion->update(['tipo_de_condicion_id' => $destino->id, 'orden' => (int) $destino->opciones()->max('orden') + 1]);
            }

            // Lo que quedó apuntando a la condición: las fichas con su título (con o sin dato de la lista).
            $origen->campos()->get()->each(function (EmpresaCampo $c) use ($destino, &$fichas) {
                $c->update(['titulo' => $destino->nombre, 'tipo_de_condicion_id' => $destino->id]);
                $fichas++;
            });

            $origen->delete();

            return $fichas;
        });
    }

    private function sinRepetirOpcion(int $tipoId, string $texto, int $salvo): void
    {
        $k = self::clave($texto);

        if ($k === '') {
            throw ValidationException::withMessages(['texto' => 'Falta el dato.']);
        }

        $repetida = OpcionDeCondicion::where('tipo_de_condicion_id', $tipoId)->where('id', '!=', $salvo)->get()
            ->contains(fn (OpcionDeCondicion $o) => self::clave($o->texto) === $k);

        if ($repetida) {
            throw ValidationException::withMessages(['texto' => 'Ya está en la lista.']);
        }
    }
}
