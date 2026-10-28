<?php

namespace App\Http\Controllers;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Material;
use App\Services\Migracion\OriginalesDelAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Traer los datos del sistema anterior, desde la pantalla.
 *
 * La importacion existe como comando y se puede correr desde la consola, pero
 * quien la va a usar es la empresa: tiene que poder ver que hay cargado, hacer
 * una prueba en seco y recien despues aplicar.
 *
 * Es la operacion mas destructiva del sistema —borra TODO lo cargado y lo
 * reemplaza— asi que pide confirmacion escrita y solo la puede hacer un
 * administrador.
 */
class MigracionController extends Controller
{
    /** Lo que hay que escribir para confirmar. */
    private const CONFIRMACION = 'REEMPLAZAR TODO';

    private function soloAdministradores(Request $request): void
    {
        abort_unless(
            $request->user()?->role === 'Administrador',
            403,
            'Solo un administrador puede reemplazar los datos del sistema.',
        );
    }

    /** Que hay cargado hoy. */
    public function estado(Request $request)
    {
        $this->soloAdministradores($request);

        return [
            'hay' => [
                'empresas' => Empresa::count(),
                'cotizaciones' => Consulta::count(),
                'lineas' => ConsultaLinea::count(),
                'materiales' => Material::count(),
                'materiales_con_densidad' => Material::whereNotNull('densidad')->count(),
                'formas' => Forma::count(),
                'formas_con_calculo' => Forma::whereNotNull('expresion')->count(),
            ],
            'carpeta_sugerida' => storage_path('app/migracion'),
            'archivos_necesarios' => [
                'Materiales.csv', 'FamiliaMaterial.csv', 'Forma.csv',
                'Unidades.csv', 'empresas.csv', 'cotizaciones.csv',
            ],
            'confirmacion' => self::CONFIRMACION,
        ];
    }

    /**
     * Corre la importacion: en seco por defecto.
     *
     * Sin "aplicar" no escribe nada y devuelve el conteo de lo que haria. Con
     * "aplicar" hace falta ademas escribir la frase de confirmacion: es un
     * borrado completo y no puede pasar por un clic distraido.
     */
    public function importar(Request $request)
    {
        $this->soloAdministradores($request);

        $datos = $request->validate([
            'carpeta' => ['required', 'string'],
            'aplicar' => ['boolean'],
            'confirmacion' => ['nullable', 'string'],
        ]);

        $aplicar = (bool) ($datos['aplicar'] ?? false);

        if ($aplicar && ($datos['confirmacion'] ?? '') !== self::CONFIRMACION) {
            return response()->json([
                'mensaje' => 'Para reemplazar los datos hay que escribir "'
                    .self::CONFIRMACION.'" en el campo de confirmacion.',
            ], 422);
        }

        if (! is_dir($datos['carpeta'])) {
            return response()->json(['mensaje' => 'No existe la carpeta '.$datos['carpeta']], 422);
        }

        $antes = $this->resumen();

        Artisan::call('importar:sistema-viejo', array_filter([
            '--carpeta' => $datos['carpeta'],
            '--aplicar' => $aplicar,
        ]));

        return [
            'aplicado' => $aplicar,
            'salida' => Artisan::output(),
            'antes' => $antes,
            'ahora' => $aplicar ? $this->resumen() : $antes,
        ];
    }

    /** @return array<string, int> */
    private function resumen(): array
    {
        return [
            'empresas' => Empresa::count(),
            'cotizaciones' => Consulta::count(),
            'materiales' => Material::count(),
        ];
    }

    /**
     * Antes y ahora, lado a lado.
     *
     * TEMPORAL. Existe para que CORDES vea con sus propios datos que nada se
     * perdio ni se invento en el camino: a la izquierda la fila del Access tal
     * cual, a la derecha lo que quedo cargado. Cuando la revisen y den el OK,
     * se borra este metodo, su ruta y la pantalla.
     */
    public function comparacion(Request $request)
    {
        $this->soloAdministradores($request);

        $datos = $request->validate([
            'que' => ['required', Rule::in(['materiales', 'empresas', 'cotizaciones'])],
            'buscar' => ['nullable', 'string', 'max:80'],
            'pagina' => ['nullable', 'integer', 'min:1'],
            // Para ver el antes/ahora de UNA cotización, no las de toda la empresa.
            'cotizacion' => ['nullable', 'integer'],
        ]);

        $archivo = match ($datos['que']) {
            'materiales' => 'Materiales.csv',
            'empresas' => 'empresas.csv',
            'cotizaciones' => 'cotizaciones.csv',
        };

        $pagina = max(1, (int) ($datos['pagina'] ?? 1));
        $porPagina = 20;
        $buscar = trim($datos['buscar'] ?? '');

        /*
          En el servidor el "antes" esta en la base, y con el indice completo
          son 165.000 filas: se filtran y se paginan alla, y se decodifican
          solo las veinte de la pantalla. Leerlas todas en memoria, como con
          las 8.048 de septiembre, ya no entra.
        */
        if (! is_file(OriginalesDelAccess::carpeta().'/'.$archivo)) {
            $consulta = DB::table('migracion_originales')->where('archivo', $archivo);

            if ($datos['que'] === 'cotizaciones' && ! empty($datos['cotizacion'])) {
                $cual = Consulta::withTrashed()->find((int) $datos['cotizacion']);

                // Las que se sumaron del indice completo se encuentran por su
                // huella; las de septiembre, por su lugar en la lista.
                if ($cual?->origen_clave) {
                    $consulta->where('clave', $cual->origen_clave);
                } else {
                    $pos = $cual ? $this->deSeptiembre()->search($cual->id) : false;
                    $consulta->whereNull('clave')->where('fila', $pos === false ? -1 : $pos);
                }
            }

            if ($buscar !== '') {
                // Tambien con el Ø puesto: nadie va a buscar escribiendo "Ý".
                // MySQL pasa a minusculas los acentos y SQLite solo la a-z: van las dos.
                $formas = collect([$buscar, str_replace('Ø', 'Ý', $buscar)])
                    ->flatMap(fn ($b) => DB::getDriverName() === 'sqlite' ? [mb_strtolower($b), strtolower($b)] : [mb_strtolower($b)])
                    ->unique();

                $consulta->where(function ($q) use ($formas) {
                    foreach ($formas as $forma) {
                        $q->orWhereRaw('LOWER(datos) LIKE ?', ['%'.$forma.'%']);
                    }
                });
            }

            $total = (clone $consulta)->count();
            $enPantalla = $consulta->orderBy('fila')->forPage($pagina, $porPagina)->get(['fila', 'clave', 'datos'])
                ->map(fn ($f) => ['i' => (int) $f->fila, 'clave' => $f->clave, 'fila' => json_decode($f->datos, true) ?: []])
                ->all();

            return [
                'total' => $total,
                'pagina' => $pagina,
                'paginas' => max(1, (int) ceil($total / $porPagina)),
                'filas' => match ($datos['que']) {
                    'materiales' => $this->compararMateriales($enPantalla),
                    'empresas' => $this->compararEmpresas($enPantalla),
                    'cotizaciones' => $this->compararCotizaciones($enPantalla),
                },
            ];
        }

        /*
          En la maquina donde se importo, del archivo. Se filtra y se pagina
          sobre el archivo, y recien despues se va a
          buscar a la base las veinte fichas de la pantalla. Armar las 8048
          comparaciones enteras para mostrar veinte se comia la memoria.

          El numero de fila del archivo viaja con la fila porque es lo que
          empareja las cotizaciones, que no tienen otra clave: si el filtro
          saca filas del medio, el par se mantiene igual.
        */
        $filas = [];

        foreach ($this->leerCsv($archivo) as $i => $fila) {
            $filas[] = ['i' => $i, 'fila' => $fila];
        }

        /*
          Una cotización puntual: desde su ficha, "Antes y después" abre esta
          pantalla apuntando a ESA cotización y no a las de toda la empresa.

          Las cotizaciones se emparejan con el Access por posición —el archivo
          no trae un número propio, la columna ID es la de la empresa y se
          repite—, así que la fila de una cotización es su lugar en la lista
          ordenada por id. withTrashed para que un borrador en la papelera no
          corra las posiciones del resto.
        */
        if ($datos['que'] === 'cotizaciones' && ! empty($datos['cotizacion'])) {
            $pos = Consulta::withTrashed()->orderBy('id')->pluck('id')->search((int) $datos['cotizacion']);

            $filas = $pos === false
                ? []
                : array_values(array_filter($filas, fn (array $f) => $f['i'] === $pos));
        }

        if ($buscar !== '') {
            $filas = array_values(array_filter($filas, function (array $f) use ($buscar) {
                $texto = implode(' ', $f['fila']);

                // Tambien con el Ø puesto: nadie va a buscar escribiendo "Ý".
                return mb_stripos($texto.' '.str_replace('Ý', 'Ø', $texto), $buscar) !== false;
            }));
        }

        $enPantalla = array_slice($filas, ($pagina - 1) * $porPagina, $porPagina);

        return [
            'total' => count($filas),
            'pagina' => $pagina,
            'paginas' => max(1, (int) ceil(count($filas) / $porPagina)),
            'filas' => match ($datos['que']) {
                'materiales' => $this->compararMateriales($enPantalla),
                'empresas' => $this->compararEmpresas($enPantalla),
                'cotizaciones' => $this->compararCotizaciones($enPantalla),
            },
        ];
    }

    /** Las medidas de la linea en una linea de texto, si tiene alguna. */
    private function medidasLegibles(\App\Models\ConsultaLinea $l): ?string
    {
        $partes = array_filter([
            $l->diametro_mm !== null ? 'Ø '.$this->sinCeros($l->diametro_mm) : null,
            $l->ancho_mm !== null ? 'ancho '.$this->sinCeros($l->ancho_mm) : null,
            $l->espesor_mm !== null ? 'esp. '.$this->sinCeros($l->espesor_mm) : null,
            $l->largo_mm !== null ? 'largo '.$this->sinCeros($l->largo_mm) : null,
        ]);

        return $partes === [] ? null : implode(' × ', $partes).' mm';
    }

    private function sinCeros(string|float $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, ',', ''), '0'), ',');
    }

    /**
     * Una columna del Access, solo si trae algo.
     *
     * El lado "antes" tiene que mostrar TODA columna que el sistema use, no
     * las tres o cuatro mas vistosas. Mostrando de menos, el responsable o la
     * familia aparecian del lado nuevo y en ninguna parte del viejo: quien
     * revisaba veia un dato salido de la nada, justo en la pantalla que existe
     * para probar que no se invento nada.
     *
     * El "0" cuenta como vacio: el Access guardaba los numericos en cero
     * cuando no los cargaban.
     *
     * @param  array<string, string>  $fila
     * @return array<string, string>
     */
    private function columna(array $fila, string $col, string $etiqueta): array
    {
        $valor = trim((string) ($fila[$col] ?? ''));

        return $valor === '' || $valor === '0' ? [] : [$etiqueta => $valor];
    }

    /**
     * @param  list<array{i: int, fila: array<string, string>}>  $enPantalla
     * @return list<array<string, mixed>>
     */
    private function compararMateriales(array $enPantalla): array
    {
        $enBase = Material::with('alias')
            ->whereIn('origen_id', array_map(fn ($f) => trim((string) $f['fila']['IDMaterial']), $enPantalla))
            ->get()->keyBy('origen_id');

        // La familia del material vive en otro archivo del Access, enganchada
        // por IDFamilia. Sin esto, del lado viejo se veia un numero y del nuevo
        // el nombre: no habia forma de comparar.
        $familias = [];

        foreach ($this->leerCsv('FamiliaMaterial.csv') as $fam) {
            $familias[trim((string) $fam['IDFamiliaMaterial'])] = trim((string) $fam['FamiliaMaterial']);
        }

        return array_map(function (array $f) use ($enBase, $familias) {
            $m = $f['fila'];
            $ficha = $enBase->get(trim((string) $m['IDMaterial']));
            $densidad = (float) ($m['Densidad'] ?? 0);

            return [
                'clave' => $m['IDMaterial'],
                'antes' => [
                    'Nombre' => $m['Nombre Material'],
                    'Codigo' => $m['CódigoMaterial'],
                    'Familia' => $familias[trim((string) ($m['IDFamilia'] ?? ''))] ?? '—',
                    'Densidad' => $densidad > 0 ? number_format($densidad, 2, ',', '') : '0  (sin cargar)',
                ],
                'ahora' => $ficha === null ? null : [
                    'Nombre' => $ficha->nombre,
                    'Familia' => $ficha->familia,
                    'Densidad' => $ficha->densidad !== null
                        ? number_format((float) $ficha->densidad, 2, ',', '')
                        : 'sin densidad — no calcula peso',
                    'Se busca tambien como' => $ficha->alias->pluck('alias')->implode(', ') ?: '—',
                ],
            ];
        }, $enPantalla);
    }

    /**
     * @param  list<array{i: int, fila: array<string, string>}>  $enPantalla
     * @return list<array<string, mixed>>
     */
    private function compararEmpresas(array $enPantalla): array
    {
        $enBase = Empresa::with(['localidad', 'provincia', 'pais', 'contactos.medios', 'rubro', 'relaciones'])
            ->whereIn('codigo_indice', array_map(fn ($f) => trim((string) $f['fila']['ID']), $enPantalla))
            ->get()->keyBy('codigo_indice');

        return array_map(function (array $f) use ($enBase) {
            $e = $f['fila'];
            $ficha = $enBase->get(trim((string) $e['ID']));
            $telefonos = $ficha?->contactos->flatMap->medios->pluck('valor')->implode(' · ');

            return [
                'clave' => $e['ID'],
                'antes' => [
                    'Empresa' => $e['EMPRESA'],
                    ...$this->columna($e, 'RESPONS', 'Responsable (RESPONS)'),
                    'Direccion' => $e['DIRECCION'],
                    'Localidad' => trim(($e['LOCALIDAD'] ?? '').'  '.($e['PROVINCIA'] ?? '').'  '.($e['PAIS'] ?? '')),
                    ...$this->columna($e, 'CODPOSTAL', 'Codigo postal (CODPOSTAL)'),
                    'Telefonos' => trim(collect(['TELEF1', 'TELEF2', 'TELEF3', 'TELEF4', 'TELEF5', 'FAX'])
                        ->map(fn ($c) => trim((string) ($e[$c] ?? '')))
                        ->filter()->implode(' · ')),
                    // Estas cuatro terminan en la observacion de la ficha.
                    ...$this->columna($e, 'TELEDISC', 'Caracteristica (TELEDISC)'),
                    ...$this->columna($e, 'TELEX', 'Telex (TELEX)'),
                    ...$this->columna($e, 'OBSERV', 'Observacion (OBSERV)'),
                    ...$this->columna($e, 'HORARIOS', 'Horarios (HORARIOS)'),
                    ...$this->columna($e, 'RUBRO', 'Rubro (RUBRO)'),
                    ...$this->columna($e, 'RELAC', 'Relacion (RELAC)'),
                ],
                'ahora' => $ficha === null ? null : [
                    'Empresa' => $ficha->nombre,
                    ...($ficha->contactos->first()?->nombre
                        ? ['Contacto' => $ficha->contactos->first()->nombre]
                        : []),
                    'Direccion' => $ficha->direccion ?? '—',
                    'Localidad' => collect([$ficha->localidad?->nombre, $ficha->provincia?->nombre, $ficha->pais?->nombre])
                        ->filter()->implode(', ') ?: '—',
                    ...($ficha->codigo_postal ? ['Codigo postal' => $ficha->codigo_postal] : []),
                    'Telefonos' => $telefonos ?: 'ninguno cargado',
                    // Donde fueron a parar TELEDISC, TELEX, OBSERV y HORARIOS.
                    ...($ficha->observacion_general
                        ? ['Observacion de la ficha' => $ficha->observacion_general]
                        : []),
                    ...($ficha->rubro?->nombre ? ['Rubro' => $ficha->rubro->nombre] : []),
                    ...($ficha->relaciones->isNotEmpty()
                        ? ['Relacion' => $ficha->relaciones->pluck('relacion')->implode(', ')]
                        : []),
                ],
            ];
        }, $enPantalla);
    }

    /**
     * Las cotizaciones se emparejan por el orden del archivo.
     *
     * No hay un numero de cotizacion en el Access —la columna ID es la de la
     * empresa y se repite— asi que van en el orden en que se importaron, que
     * es el del archivo. El nombre de la empresa queda a la vista de los dos
     * lados: si alguna vez se desalinea, se ve de una.
     *
     * @param  list<array{i: int, fila: array<string, string>}>  $enPantalla
     * @return list<array<string, mixed>>
     */
    private function compararCotizaciones(array $enPantalla): array
    {
        // La lista de ids ordenada es liviana; las fichas se traen solo para
        // las veinte posiciones que se estan mirando. withTrashed para que la
        // posicion sea siempre la misma, haya o no borradores en la papelera.
        $ids = $this->deSeptiembre();

        // Las sumadas del indice completo traen la huella de su cotizacion.
        $porClave = Consulta::withTrashed()
            ->whereIn('origen_clave', array_filter(array_map(fn ($f) => $f['clave'] ?? null, $enPantalla)))
            ->pluck('id', 'origen_clave');

        $idDe = fn (array $f) => ! empty($f['clave']) ? ($porClave[$f['clave']] ?? null) : $ids->get($f['i']);

        $enBase = Consulta::withTrashed()->with(['lineas.material', 'lineas.forma', 'empresa'])
            ->whereIn('id', array_filter(array_map($idDe, $enPantalla)))
            ->get()->keyBy('id');

        return array_map(function (array $f) use ($idDe, $enBase) {
            $c = $f['fila'];
            $consulta = $enBase->get($idDe($f));

            $renglones = [];

            for ($n = 1; $n <= 5; $n++) {
                $texto = trim((string) ($c["PE{$n}"] ?? ''));
                $precio = (float) ($c["PR{$n}"] ?? 0);

                if ($texto !== '' || $precio > 0) {
                    $renglones[] = ($texto ?: '(sin texto)').($precio > 0 ? '     '.$precio : '');
                }
            }

            /*
              El lado "antes" muestra TODAS las columnas que el Access trae y
              el sistema usa, no solo las cuatro visibles.

              Mostrando de menos, el nombre del responsable aparecia del lado
              nuevo y en ninguna parte del viejo: quien revisaba veia un dato
              salido de la nada justo en la pantalla que existe para probar que
              no se invento nada. CRESP lo traen 3.845 de las 8.048 filas.
            */
            return [
                'clave' => $f['i'] + 1,
                'antes' => [
                    'Empresa' => $c['EMPRESA'],
                    'Fecha' => $c['FECHA'],
                    'Moneda' => $c['MON'],
                    ...$this->columna($c, 'CRESP', 'Responsable (CRESP)'),
                    ...$this->columna($c, 'NOTA', 'Nota (NOTA)'),
                    ...$this->columna($c, 'NROFACT', 'Nro. de factura (NROFACT)'),
                    ...$this->columna($c, 'DOLAR', 'Tipo de cambio (DOLAR)'),
                    'Los cinco renglones' => implode("\n", $renglones),
                ],
                'ahora' => $consulta === null ? null : [
                    'Empresa' => $consulta->empresa?->nombre ?? '—',
                    'Fecha' => $consulta->fecha?->format('d-m-Y') === '01-01-1900'
                        ? 'sin fecha usable'
                        : $consulta->fecha?->format('d-m-Y'),
                    'Es' => $consulta->tipo === 'Observacion'
                        ? 'una observacion sobre el cliente (ningun renglon tenia precio)'
                        : $consulta->lineas->count().' articulo(s) cotizado(s)',
                    'Articulos' => $consulta->lineas
                        ->map(fn ($l) => trim(
                            ($l->cantidad !== null ? rtrim(rtrim((string) $l->cantidad, '0'), '.').' × ' : '')
                            .$l->descripcion
                            .($l->precio_unitario !== null ? '     '.$l->precio_unitario : ''),
                        ))
                        ->implode("\n") ?: '—',
                    /*
                      Lo que se reconocio dentro del texto: el material, la
                      forma y las medidas, que en el Access estaban escritos
                      adentro de la descripcion y en ningun campo. Es la parte
                      que hay que mirar con mas atencion, porque es lo unico
                      que no viene copiado tal cual sino leido.
                    */
                    'Reconocido dentro del texto' => $consulta->lineas
                        ->map(function ($l) {
                            $partes = array_filter([
                                $l->material?->nombre,
                                $l->forma?->nombre,
                                $this->medidasLegibles($l),
                            ]);

                            return $partes === [] ? null : implode('  ·  ', $partes);
                        })
                        ->filter()->implode("\n") ?: 'nada: la descripcion queda tal cual',
                    'Lo que no es articulo quedo en la nota' => $consulta->nota ?? '—',
                ],
            ];
        }, $enPantalla);
    }

    /**
     * Las cotizaciones de septiembre, en orden: la fila N del archivo es la N-esima.
     *
     * Solo hacen falta las primeras —tantas como filas de septiembre sin
     * huella—: traer los ids de todas las cotizaciones del indice completo
     * para emparejar veinte tardaba segundos.
     */
    private function deSeptiembre(): \Illuminate\Support\Collection
    {
        $cuantas = DB::table('migracion_originales')->where('archivo', 'cotizaciones.csv')->whereNull('clave')->count();

        // Sin filas en la base (la maquina con los CSV), todas, como antes.
        $ids = Consulta::withTrashed()->orderBy('id');

        return ($cuantas > 0 ? $ids->limit($cuantas) : $ids)->pluck('id');
    }

    /**
     * El CSV tal cual salio del Access.
     *
     * Sin limpiar nada a proposito: el lado "antes" tiene que mostrar el Ý
     * donde iba un Ø, la fecha 01/10/2424 y la mascara "-    -" sin telefono.
     * Es la mitad de la comparacion.
     *
     * @return list<array<string, string>>
     */
    private function leerCsv(string $archivo): array
    {
        $ruta = OriginalesDelAccess::carpeta().'/'.$archivo;

        /*
          El archivo primero, la base si no esta.

          En la maquina donde se importo estan los CSV y se leen de ahi. En el
          servidor no: no se versionan ni se copian a la imagen porque son
          datos de clientes. Alla la copia que hay es la que viajo adentro de
          la base, puesta por `migracion:guardar-originales`.
        */
        return is_file($ruta)
            ? OriginalesDelAccess::delArchivo($ruta)
            : OriginalesDelAccess::deLaBase($archivo);
    }

    /**
     * Los materiales que quedaron sin densidad.
     *
     * Son los que el sistema anterior nunca completo. Sin densidad no se puede
     * calcular peso: la pantalla avisa y se carga a mano. Esta lista es para
     * que la empresa los vaya completando.
     */
    public function materialesSinDensidad(Request $request)
    {
        $this->soloAdministradores($request);

        return [
            'total' => Material::whereNull('densidad')->count(),
            'por_familia' => DB::table('materiales')
                ->selectRaw('familia, COUNT(*) as sin_densidad')
                ->whereNull('densidad')
                ->groupBy('familia')
                ->orderByDesc('sin_densidad')
                ->get(),
        ];
    }
}
