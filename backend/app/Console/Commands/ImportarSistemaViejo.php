<?php

namespace App\Console\Commands;

use App\Models\CondicionHabitual;
use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Contacto;
use App\Models\ContactoMedio;
use App\Models\Empresa;
use App\Models\EmpresaRelacion;
use App\Models\Forma;
use App\Models\Localidad;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Moneda;
use App\Models\Pais;
use App\Models\Provincia;
use App\Models\Rubro;
use App\Models\TipoMedio;
use App\Models\Unidad;
use App\Models\User;
use App\Services\Migracion\CatalogoViejo;
use App\Services\Migracion\OriginalesDelAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Trae los datos reales del sistema anterior y saca los de ejemplo.
 *
 * Son dos bases de Access exportadas a CSV:
 *  · Mater_be.mdb  — 1249 materiales, 250 formas, unidades
 *  · INDICE_TEL    — 966 empresas y 8048 cotizaciones historicas
 *
 * Los nombres quedan como los escribe la empresa: "Acero AISI 304" y no
 * "AISI 304". Es su vocabulario y es el que su gente reconoce.
 *
 * Lo que NO hace:
 *  · no inventa densidades — el 0 del Access significa "no la cargaron"
 *  · no asigna formulas por parecido de nombre
 *  · no convierte australes a pesos
 *  · no toca usuarios ni permisos
 *
 * Con --sumar entra solo el indice telefonico (empresas.csv y
 * cotizaciones.csv) y NO se borra nada: ya hay gente cargando en el sistema
 * nuevo. Lo que ya estaba se reconoce y se saltea, y entra lo que falta:
 *  · la empresa, por el ID del indice; la ficha que crearon en el sistema
 *    nuevo, por el nombre, y se le pone el ID en vez de duplicarla
 *  · la cotizacion, por empresa + fecha + los cinco renglones tal cual
 *
 * Pais, provincia, localidad, rubro y unidad entran como quedaron despues de
 * las limpiezas del 26-09 y del 01-10 (ajustes_del_catalogo, rubros_repetidos,
 * localidades_recuperadas): sin eso, el indice completo volvia a crear
 * Buenos Aires escrita de nueve formas.
 */
class ImportarSistemaViejo extends Command
{
    protected $signature = 'importar:sistema-viejo
        {--carpeta= : donde estan los CSV exportados del Access}
        {--sumar : solo el indice telefonico, agregando lo que falta sin borrar nada}
        {--desde-dos : el texto viene de DOS leido como Windows ("PE¥A" es PEÑA)}
        {--aplicar : escribe en la base; sin esto solo informa}';

    protected $description = 'Importa los datos reales del sistema anterior';

    /**
     * Las 24 provincias argentinas y como las escribian, sin acentos ni puntos.
     *
     * Es una lista cerrada a proposito: el indice completo traia 126
     * "provincias" nuevas —ciudades, barrios, abreviaturas— y crearlas era
     * volver a ensuciar lo que la limpieza del 26-09 junto. Lo que no esta
     * aca no se adivina: queda escrito en la ficha para elegirlo a mano.
     */
    private const PROVINCIAS_ARGENTINAS = [
        'Buenos Aires' => ['BUENOSAIRES', 'BSAS', 'BSAIRES', 'BAIRES', 'BS', 'PROVBSAS', 'PCIABSAS', 'PCIADEBSAS',
            'PROVDEBSAS', 'PROVBUENOSAIRES', 'PCIABUENOSAIRES', 'PROVINCIADEBUENOSAIRES', 'PCIADEBUENOSAIRES',
            'PROVDEBUENOSAIRES', 'PROVINCIABUENOSAIRES', 'PROVINCIABSAS', 'PBA', 'GBA'],
        'Ciudad Autónoma de Buenos Aires' => ['CIUDADAUTONOMADEBUENOSAIRES', 'CABA', 'CAPFED', 'CAPITAL', 'CAPITALFEDE',
            'CAPITALFEDERAL', 'CAPFEDERAL', 'CAPITALFED', 'CFEDERAL', 'CF', 'CIUDADDEBUENOSAIRES', 'CIUDADDEBSAS', 'CDADDEBSAS'],
        'Catamarca' => ['CATAMARCA'],
        'Chaco' => ['CHACO'],
        'Chubut' => ['CHUBUT'],
        'Córdoba' => ['CORDOBA', 'CBA'],
        'Corrientes' => ['CORRIENTES', 'CTES'],
        'Entre Ríos' => ['ENTRERIOS', 'ERIOS'],
        'Formosa' => ['FORMOSA'],
        'Jujuy' => ['JUJUY'],
        'La Pampa' => ['LAPAMPA'],
        'La Rioja' => ['LARIOJA'],
        'Mendoza' => ['MENDOZA', 'MZA'],
        'Misiones' => ['MISIONES'],
        'Neuquén' => ['NEUQUEN', 'NQN'],
        'Río Negro' => ['RIONEGRO'],
        'Salta' => ['SALTA'],
        'San Juan' => ['SANJUAN'],
        'San Luis' => ['SANLUIS'],
        'Santa Cruz' => ['SANTACRUZ', 'STACRUZ'],
        'Santa Fe' => ['SANTAFE', 'STAFE', 'SANAFE', 'SANTAFED', 'SFE'],
        'Santiago del Estero' => ['SANTIAGODELESTERO', 'SGODELESTERO', 'STGODELESTERO', 'SDELESTERO', 'SGOESTERO'],
        'Tierra del Fuego' => ['TIERRADELFUEGO', 'TDELFUEGO', 'TFUEGO', 'FUEGO', 'TDF', 'TIERRADELF', 'TIERRAFUEGO'],
        'Tucumán' => ['TUCUMAN', 'TUC'],
    ];

    /**
     * Los paises que aparecen en el indice, y como los escribian.
     *
     * Tambien es una lista cerrada: en la columna PAIS hay codigos postales,
     * provincias, internos de telefono y nombres sueltos ("OSCAR", "24 y 25").
     * Sin pais es Argentina, que es de donde son casi todas.
     */
    private const PAISES = [
        'Argentina' => ['ARGENTINA', 'ARG', 'REPUBLICAARGENTINA', 'RA'],
        'Alemania' => ['ALEMANIA'],
        'Bolivia' => ['BOLIVIA'],
        'Brasil' => ['BRASIL', 'BRAZIL'],
        'Chile' => ['CHILE'],
        'China' => ['CHINA'],
        'Colombia' => ['COLOMBIA'],
        'Ecuador' => ['ECUADOR'],
        'España' => ['ESPANA', 'SPAIN'],
        'Estados Unidos' => ['ESTADOSUNIDOS', 'EEUU', 'USA', 'US'],
        'Francia' => ['FRANCIA'],
        'Holanda' => ['HOLANDA', 'PAISESBAJOS'],
        'Hungria' => ['HUNGRIA'],
        'Inglaterra' => ['INGLATERRA', 'GBRETANA', 'GRANBRETANA', 'REINOUNIDO'],
        'Italia' => ['ITALIA'],
        'Mexico' => ['MEXICO'],
        'Paraguay' => ['PARAGUAY', 'PARAGUAYA'],
        'Peru' => ['PERU'],
        'Portugal' => ['PORTUGAL'],
        'Suiza' => ['SUIZA'],
        'Uruguay' => ['URUGUAY'],
        'Venezuela' => ['VENEZUELA'],
    ];

    /** Las que se apagaron el 26-09 por repetidas, y la que quedo. */
    private const UNIDADES_REPETIDAS = ['C/U' => 'UN', 'M' => 'MT', 'TON' => 'TN'];

    /** Lo que dice la nota de cada cotizacion que vino del Access. */
    private const NOTA_IMPORTADA = 'Importada del sistema anterior.';

    /** @var array<string, int> */
    private array $conteo = [];

    private bool $sumar = false;

    private bool $aplicar = false;

    private bool $desdeDos = false;

    /** @var array<string, int> archivo => numero de la proxima fila original */
    private array $proximaFila = [];

    /** @var list<array<string, mixed>> filas originales esperando el insert */
    private array $originalesNuevos = [];

    /** @var array<string, list<string>> ID|fecha => los renglones de septiembre de esa empresa ese dia */
    private array $deSeptiembre = [];

    /** @var array<string, array<string, int>> 'nuevo'|'septiembre' => caracter que solo esta de ese lado => veces */
    private array $caracteresDistintos = [];

    /** @var array<string, array<string, int>> 'pais'|'provincia' => texto que no se reconocio => veces */
    private array $sinReconocer = [];

    /** @var array<string, true> ID que ya estaba y en el Access nuevo es otra empresa */
    private array $conflictos = [];

    /**
     * ID que en septiembre llego solo en una cotizacion de otra empresa.
     *
     * @var array<string, array{0: string, 1: int, 2?: int}> ID => [nombre plano de esa ficha, su id, id de la empresa del Access]
     */
    private array $compartidos = [];

    /**
     * Los catalogos por nombre plano: pais, pais|provincia,
     * pais|provincia|localidad y rubro. En el informe, lo que se crearia
     * queda con id null.
     *
     * @var array<string, ?int>
     */
    private array $paises = [];

    /** @var array<string, ?int> */
    private array $provincias = [];

    /** @var array<string, ?int> */
    private array $localidades = [];

    /** @var array<string, array<string, array{0: ?int, 1: ?int}>> pais|localidad => [localidad, provincia] */
    private array $localidadesDelPais = [];

    /** @var array<string, ?int> */
    private array $rubros = [];

    /** @var array<string, string> ID del indice que ya estaba => su nombre plano */
    private array $yaEstaban = [];

    /** @var array<string, true> ID del indice que entra en esta corrida */
    private array $nuevas = [];

    /** @var array<string, int> nombre plano => la unica ficha sin ID del indice con ese nombre */
    private array $sinCodigo = [];

    /** @var array<string, int> nombre plano => cuantas filas del Access lo usan */
    private array $nombresDelArchivo = [];

    /** @var array<string, true> */
    private array $nombresQueYaEstaban = [];

    /** @var array<string, true> huella (empresa|fecha|texto) de cada cotizacion importada */
    private array $cotizacionesQueYaEstaban = [];

    private string $ultimaImportada = '1900-01-01';

    private function contar(string $que, int $cuantos = 1): void
    {
        $this->conteo[$que] = ($this->conteo[$que] ?? 0) + $cuantos;
    }

    public function handle(): int
    {
        $carpeta = rtrim((string) $this->option('carpeta'), '/');
        $aplicar = (bool) $this->option('aplicar');
        $this->sumar = (bool) $this->option('sumar');

        // Laravel reusa la misma instancia si el comando corre dos veces en un
        // proceso: sin esto, la segunda corrida arrastra lo que conto la primera.
        $this->aplicar = $aplicar;
        $this->desdeDos = (bool) $this->option('desde-dos');
        $this->conteo = $this->yaEstaban = $this->nuevas = $this->sinCodigo = $this->conflictos = $this->compartidos = [];
        $this->nombresDelArchivo = $this->nombresQueYaEstaban = $this->cotizacionesQueYaEstaban = [];
        $this->paises = $this->provincias = $this->localidades = $this->localidadesDelPais = $this->rubros = $this->sinReconocer = [];
        $this->deSeptiembre = $this->caracteresDistintos = $this->proximaFila = $this->originalesNuevos = [];
        $this->ultimaImportada = '1900-01-01';

        $archivos = $this->sumar
            ? ['empresas', 'cotizaciones']
            : ['Materiales', 'FamiliaMaterial', 'Forma', 'Unidades', 'empresas', 'cotizaciones'];

        foreach ($archivos as $a) {
            if (! is_file("{$carpeta}/{$a}.csv")) {
                $this->error("Falta {$carpeta}/{$a}.csv");

                return self::FAILURE;
            }
        }

        if (! $aplicar) {
            $this->warn('Modo informe: no se escribe nada. Agregá --aplicar para hacerlo.');
        }

        // Las cotizaciones se guardan a nombre de alguien (usuario_id es NOT
        // NULL). Sin usuarios la importacion borra las quince tablas y recien
        // despues falla al escribir la primera cotizacion: se corta antes.
        if ($aplicar && User::count() === 0) {
            $this->error('No hay usuarios cargados: las cotizaciones no se pueden guardar a nombre de nadie.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($carpeta, $aplicar) {
            if ($this->sumar) {
                $this->loQueYaEsta($carpeta);
            } else {
                if ($aplicar) {
                    $this->sacarLoDeEjemplo();
                }

                $this->formas($carpeta, $aplicar);
                $this->unidades($carpeta, $aplicar);
                $this->materiales($carpeta, $aplicar);
            }

            $this->empresas($carpeta, $aplicar);
            $this->cotizaciones($carpeta, $aplicar);

                // Deshace todo: el modo informe cuenta pero no escribe.
                if (! $aplicar) {
                    throw new SoloInforme;
                }
            });
        } catch (SoloInforme) {
            // Esperado.
        } catch (IdsQueNoCoinciden $e) {
            $this->resumenEnPantalla();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->resumenEnPantalla();

        return self::SUCCESS;
    }

    /**
     * Los datos de ejemplo salen.
     *
     * Eran para probar. Ahora entran los reales y no pueden convivir: nadie
     * sabria cual cotizacion es de verdad. Los usuarios NO se tocan: son los
     * que permiten entrar al sistema.
     */
    private function sacarLoDeEjemplo(): void
    {
        $this->contar('cotizaciones de ejemplo borradas', Consulta::count());
        $this->contar('empresas de ejemplo borradas', Empresa::count());

        /*
          De adentro hacia afuera: cada tabla antes de la que la sostiene.

          Las cotizaciones se apuntan entre si —una copiada de otra— asi que
          primero se corta esa referencia; si no, MySQL no deja borrar ninguna.
        */
        DB::table('consultas')->update(['copiada_de_id' => null]);

        foreach ([
            'consulta_linea_opciones',
            'consulta_lineas',
            'consulta_condiciones',
            'impresiones',
            'observaciones',
            'consultas',
            'contacto_medios',
            'contactos',
            'empresa_campos',
            'empresa_enlaces',
            'empresa_relacion',
            'razones_sociales',
            'empresas',
            // materiales y material_alias NO se borran: el catalogo se fusiona
            // por origen_id. Ahi vive lo que curo la empresa —densidades
            // confirmadas, UNS, W.Nr, alias— y el Access no lo trae.
        ] as $tabla) {
            DB::table($tabla)->delete();
        }

        // El historial apunta a empresas que ya no estan.
        DB::table('historial_cambios')->delete();
    }

    /**
     * Las 250 formas del sistema viejo.
     *
     * Solo las trece que significan exactamente lo mismo que una nuestra se
     * quedan con la formula de peso. El resto entra sin cuenta: el sistema lo
     * dice en pantalla y se cargan a mano, que es mejor que un peso equivocado.
     */
    private function formas(string $carpeta, bool $aplicar): void
    {
        $conFormula = collect(Forma::all())->keyBy('nombre');

        foreach ($this->leer("{$carpeta}/Forma.csv") as $f) {
            $nombre = trim($f['Forma'] ?? '');

            if ($nombre === '') {
                continue;
            }

            $nuestra = CatalogoViejo::FORMULAS_SEGURAS[$nombre] ?? null;
            $modelo = $nuestra ? $conFormula->get($nuestra) : null;

            $this->contar($modelo ? 'formas con calculo' : 'formas sin calculo');

            if (! $aplicar) {
                continue;
            }

            // Por el id del Access primero; si es la primera corrida, por el
            // nombre, que es como se empareja con las que ya estaban curadas.
            $forma = Forma::where('origen_id', (string) $f['IDForma'])->first()
                ?? Forma::firstOrNew(['nombre' => mb_strtoupper($nombre)]);

            $nueva = ! $forma->exists;

            $forma->origen_id = (string) $f['IDForma'];
            $forma->nombre = $forma->nombre ?: mb_strtoupper($nombre);
            $forma->clave ??= Forma::claveDesde($nombre);

            // El texto de ayuda de las que ya estaban lo escribio la empresa
            // para su pantalla: el del Access no significa lo mismo y no pisa.
            $forma->medidas_habituales ??= CatalogoViejo::texto($f['Dimensión Principal'] ?? null);

            /*
              Las 238 formas nuevas nacen apagadas. Estan todas —no se pierde
              ninguna— pero no inundan el desplegable de quien cotiza con
              formas que nadie sabe si siguen vivas. Se prenden de a una desde
              la pantalla de formas, con un clic.
            */
            if ($nueva) {
                $forma->activo = (bool) $modelo;
                $this->contar($modelo ? 'formas nuevas activas' : 'formas nuevas apagadas');
            }

            if ($modelo) {
                $forma->campos = $modelo->campos;
                $forma->expresion = $modelo->expresion;
                $forma->usa_cano = $modelo->usa_cano;
            }

            $forma->save();
        }
    }

    private function unidades(string $carpeta, bool $aplicar): void
    {
        foreach ($this->leer("{$carpeta}/Unidades.csv") as $u) {
            $codigo = mb_strtoupper(trim($u['Unidad2'] ?? ''));

            if ($codigo === '') {
                continue;
            }

            $this->contar('unidades');

            if ($aplicar) {
                $unidad = Unidad::firstOrCreate(['codigo' => $codigo], [
                    'nombre' => trim($u['Unidad'] ?? $codigo),
                    'sirve_para_vender' => true,
                    'sirve_para_facturar' => true,
                    'activo' => true,
                ]);

                $unidad->origen_id ??= (string) $u['IDUnidad'];
                $unidad->save();
            }
        }
    }

    private function materiales(string $carpeta, bool $aplicar): void
    {
        $familias = [];

        foreach ($this->leer("{$carpeta}/FamiliaMaterial.csv") as $f) {
            $familias[$f['IDFamiliaMaterial']] = CatalogoViejo::familia($f['FamiliaMaterial'] ?? '');
        }

        $vistos = [];

        foreach ($this->leer("{$carpeta}/Materiales.csv") as $m) {
            $nombre = CatalogoViejo::texto($m['Nombre Material'] ?? null);

            if ($nombre === null) {
                continue;
            }

            if (isset($vistos[mb_strtoupper($nombre)])) {
                $this->contar('materiales repetidos (se ignoran)');

                continue;
            }

            $vistos[mb_strtoupper($nombre)] = true;

            // 0 en el Access significa "no la cargaron", no "pesa cero".
            $densidad = (float) ($m['Densidad'] ?? 0);
            $this->contar($densidad > 0 ? 'materiales con densidad' : 'materiales sin densidad');

            if (! $aplicar) {
                continue;
            }

            $codigo = CatalogoViejo::texto($m['CódigoMaterial'] ?? null);
            $material = $this->materialExistente((string) $m['IDMaterial'], $nombre, $codigo);

            if ($material === null) {
                $material = new Material(['nombre' => $nombre]);
                $this->contar('materiales nuevos');
            } else {
                $this->contar('materiales que ya estaban (se completan)');

                /*
                  Manda el nombre de la empresa: "Acero AISI 304" y no
                  "AISI 304". Es como lo piden, como lo escriben en el mail y
                  como lo va a buscar su gente. El nuestro no se tira: queda de
                  alias, asi lo sigue encontrando el buscador y el lector de
                  mails, que ya venia entrenado con ese.
                */
                if (mb_strtolower($material->nombre) !== mb_strtolower($nombre)) {
                    MaterialAlias::firstOrCreate([
                        'material_id' => $material->id,
                        'alias' => $material->nombre,
                    ]);

                    $material->nombre = $nombre;
                    $this->contar('materiales que pasaron a llamarse como en el Access');
                }
            }

            $material->origen_id = (string) $m['IDMaterial'];

            // La familia tambien es la de ellos: si el catalogo entero se
            // agrupa con su vocabulario, no pueden quedar seis fichas con el
            // nuestro en otra familia y perdidas para el que las busca ahi.
            $material->familia = $familias[$m['IDFamilia']] ?? $material->familia ?? 'Sin familia';

            /*
              La densidad solo se completa si falta. Las confirmadas por CORDES
              el 09-09-2026 —8,00 para los inoxidables— no las pisa el Access,
              que trae 7,8. Cual vale lo decide la empresa, no esta importacion.
            */
            if ($material->densidad === null && $densidad > 0) {
                $material->densidad = $densidad;
            } elseif ($densidad > 0 && (float) $material->densidad !== $densidad) {
                $this->contar('materiales con densidad distinta a la del Access (gana la cargada)');
            }

            $material->activo = true;
            $material->save();

            if ($codigo !== null && mb_strtoupper($codigo) !== mb_strtoupper($nombre)) {
                MaterialAlias::firstOrCreate(['material_id' => $material->id, 'alias' => $codigo]);
            }
        }
    }

    /**
     * El material nuestro que corresponde a esa fila del Access, si ya existe.
     *
     * Por el id de origen primero. En la primera corrida ninguno lo tiene, asi
     * que se cae al nombre y despues al alias: el Access dice "Titanio Grado 2"
     * con codigo "TIT GR2", y "TIT GR2" ya es alias de nuestro "TITANIO GR2".
     * Asi se fusionan solos los que se pueden reconocer sin adivinar.
     *
     * Los que no matchean entran como material nuevo. No se emparejan por
     * parecido: "Acero AISI 316L" y "AISI 316" NO son lo mismo, y decidirlo es
     * de la empresa.
     *
     * Solo se fusiona con una ficha que ninguna otra fila del Access haya
     * reclamado todavia (origen_id vacio). El codigo del material no alcanza
     * para identificarlo: en el archivo real 48 codigos estan repetidos y
     * cubren 103 materiales. "ACE 174P" es el codigo de los SIETE tratamientos
     * del 17-4PH —H900, H925, H1025, H1050, H1075, H1100 y el base—, que son
     * siete materiales con propiedades y precio distintos, y "ACE" es a la vez
     * el de "Acero" y el de "Aluminio 2219 T87". Sin esta condicion los siete
     * 17-4PH terminaban siendo uno solo y se perdian 56 materiales.
     */
    private function materialExistente(string $origenId, string $nombre, ?string $codigo): ?Material
    {
        if ($suyo = Material::where('origen_id', $origenId)->first()) {
            return $suyo;
        }

        $comoLoTeniamos = CatalogoViejo::MISMO_MATERIAL[$nombre] ?? null;

        foreach (array_filter([$comoLoTeniamos, $nombre, $codigo]) as $texto) {
            $ficha = Material::whereNull('origen_id')
                ->where(fn ($q) => $q
                    ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($texto)])
                    ->orWhereHas('alias', fn ($a) => $a->whereRaw('LOWER(alias) = ?', [mb_strtolower($texto)])))
                ->first();

            if ($ficha) {
                return $ficha;
            }
        }

        return null;
    }

    /**
     * Lo que ya esta en el sistema, para sumar sin duplicar.
     *
     * Y antes de escribir nada, si los ID del Access nuevo son los mismos que
     * los del que ya se importo. Son la clave de todo: si el Access se
     * renumero, el ID 543 del nuevo no es la ficha 543 que ya esta, y sumar
     * colgaria cotizaciones de una empresa en otra.
     */
    private function loQueYaEsta(string $carpeta): void
    {
        // Con el nombre del Access que ya se importo y no con el de hoy: la
        // que renombraron en el sistema nuevo sigue siendo la misma empresa.
        $comoVino = [];

        foreach (OriginalesDelAccess::deLaBase('empresas.csv') as $fila) {
            $nombre = CatalogoViejo::texto($fila['EMPRESA'] ?? null);
            $id = trim((string) ($fila['ID'] ?? ''));

            if ($nombre !== null && $id !== '') {
                $comoVino[$id] = CatalogoViejo::plano($nombre);
            }
        }

        $fichaDe = [];

        foreach (Empresa::whereNotNull('codigo_indice')->get(['id', 'nombre', 'codigo_indice']) as $ficha) {
            $this->yaEstaban[(string) $ficha->codigo_indice] = $comoVino[(string) $ficha->codigo_indice] ?? CatalogoViejo::plano($ficha->nombre);
            $fichaDe[(string) $ficha->codigo_indice] = $ficha;
        }

        foreach (Empresa::pluck('nombre') as $nombre) {
            $this->nombresQueYaEstaban[CatalogoViejo::plano($nombre)] = true;
        }

        // Las que creo la gente en el sistema nuevo no tienen ID del indice.
        // Solo se reconocen si el nombre no se repite: con dos iguales no hay
        // como saber cual es.
        $this->sinCodigo = Empresa::whereNull('codigo_indice')->get(['id', 'nombre'])
            ->groupBy(fn ($e) => CatalogoViejo::plano($e->nombre))
            ->filter(fn ($iguales) => $iguales->count() === 1)
            ->map(fn ($iguales) => $iguales->first()->id)
            ->all();

        /*
          Lo que ya entro, por la fila del Access y no por como esta hoy la
          cotizacion: la gente corrige fechas, notas y textos, y una corregida
          ya no se reconoceria. Las filas originales de la primera importacion
          estan guardadas en la base; las que entran con --sumar llevan su
          huella en origen_clave.
        */
        $originales = OriginalesDelAccess::deLaBase('cotizaciones.csv');

        foreach (['empresas.csv', 'cotizaciones.csv'] as $archivo) {
            $this->proximaFila[$archivo] = (int) DB::table('migracion_originales')->where('archivo', $archivo)->max('fila') + 1;
        }

        // Si dice 0, no estan las filas de septiembre en la base: se reconoce
        // solo por como estan hoy las cotizaciones, y lo corregido entra de nuevo.
        $this->conteo['cotizaciones del Access guardadas en la base (para reconocer lo que ya entro)'] = count($originales);

        foreach ($originales as $fila) {
            $codigo = trim((string) ($fila['ID'] ?? '')) ?: null;
            [$huella, $guardada] = $this->huella($fila, $codigo, CatalogoViejo::fecha($fila['FECHA'] ?? null));
            $this->cotizacionesQueYaEstaban[$huella] = true;
            $this->deSeptiembre[$codigo.'|'.(CatalogoViejo::fecha($fila['FECHA'] ?? null) ?? '')][] = (string) $this->textoOriginal($fila);
            $this->ultimaImportada = max($this->ultimaImportada, $guardada);
        }

        foreach (DB::table('consultas')->whereNotNull('origen_clave')->pluck('origen_clave') as $huella) {
            $this->cotizacionesQueYaEstaban[$huella] = true;
        }

        // Y por si faltaran las originales, como estan hoy. Sin las copias ni
        // las revisiones: heredan la nota pero son cotizaciones nuevas.
        $importadas = DB::table('consultas')
            ->where('nota', 'like', self::NOTA_IMPORTADA.'%')
            ->whereNull('copiada_de_id')
            ->whereNull('revision_de_id')
            ->select('id_sistema', 'fecha', 'texto')
            ->cursor();

        foreach ($importadas as $c) {
            $fecha = substr((string) $c->fecha, 0, 10);
            $this->cotizacionesQueYaEstaban[md5($c->id_sistema.'|'.$fecha.'|'.md5((string) $c->texto))] = true;

            if ($fecha <= date('Y-m-d')) {
                $this->ultimaImportada = max($this->ultimaImportada, $fecha);
            }
        }

        $iguales = 0;
        $distintas = 0;

        foreach ($this->leer("{$carpeta}/empresas.csv") as $e) {
            $nombre = CatalogoViejo::texto($e['EMPRESA'] ?? null);

            if ($nombre === null) {
                continue;
            }

            $plano = CatalogoViejo::plano($nombre);
            $this->nombresDelArchivo[$plano] = ($this->nombresDelArchivo[$plano] ?? 0) + 1;

            $codigo = trim((string) ($e['ID'] ?? ''));

            if (isset($this->yaEstaban[$codigo])) {
                if ($this->yaEstaban[$codigo] === $plano) {
                    $iguales++;
                } elseif ($comoVino !== [] && ! isset($comoVino[$codigo])) {
                    /*
                      La ficha no vino del indice de septiembre: se creo de una
                      cotizacion que en el sistema viejo tenia cargado este ID,
                      y el ID es de la empresa del Access (una cotizacion de
                      la empresa A cargada con el ID de la empresa B). La del
                      Access entra como nueva, y cada cotizacion va a la que
                      nombra.
                    */
                    $this->compartidos[$codigo] = [$this->yaEstaban[$codigo], $fichaDe[$codigo]->id];
                    unset($this->yaEstaban[$codigo]);
                    $this->contar('IDs que el sistema viejo uso tambien para otra empresa: se separan por el nombre');
                } else {
                    $distintas++;
                    $this->conflictos[$codigo] = true;
                }
            }
        }

        if ($distintas > 0) {
            /*
              Mismo ID, otra empresa: no se adivina cual es. No entra la fila
              del Access ni ninguna cotizacion suya, para no colgarle a una
              ficha el historial de otra. Se listan los ID para mirarlos.
            */
            $this->contar('fichas con el mismo ID y otro nombre: no se suma nada de ellas', $distintas);
        }

        // ponytail: umbral fijo. Una correccion de nombres aca y alla pasa; que
        // uno de cada cinco no coincida ya es otra numeracion.
        if ($distintas > ($iguales + $distintas) * 0.2) {
            throw new IdsQueNoCoinciden(
                "De las fichas que ya estaban, {$distintas} de ".($iguales + $distintas)
                .' tienen en el Access nuevo otro nombre con el mismo ID: el Access se renumero.'
                .' No se sumo nada.'
            );
        }
    }

    /**
     * Si la fila del indice hay que crearla o ya esta.
     *
     * Ya esta si su ID ya esta, o si es una ficha que crearon en el sistema
     * nuevo con el mismo nombre: a esa se le pone el ID y no se toca nada mas,
     * lo que cargo la gente manda sobre el Access.
     */
    private function hayQueCrear(array $e, string $nombre, bool $aplicar): bool
    {
        $codigo = trim((string) ($e['ID'] ?? '')) ?: null;
        $plano = CatalogoViejo::plano($nombre);

        if ($codigo !== null && isset($this->conflictos[$codigo])) {
            return false;
        }

        if ($codigo !== null && isset($this->yaEstaban[$codigo])) {
            $this->contar('empresas que ya estaban');

            return false;
        }

        if ($codigo !== null && isset($this->nuevas[$codigo])) {
            $this->contar('filas con el ID repetido en el Access (entra la primera)');

            return false;
        }

        $suya = $this->sinCodigo[$plano] ?? null;

        if ($suya !== null && ($this->nombresDelArchivo[$plano] ?? 0) === 1) {
            if ($aplicar && $codigo !== null) {
                Empresa::find($suya)->update(['codigo_indice' => $codigo]);
                $this->guardarOriginal('empresas.csv', $e);
            }

            unset($this->sinCodigo[$plano]);

            if ($codigo !== null) {
                $this->nuevas[$codigo] = true;
            }

            $this->contar('fichas del sistema nuevo que estaban en el Access (se les pone el ID)');

            return false;
        }

        // Sin ID no hay con que reconocerla la proxima vez: solo entra si el
        // nombre no esta, asi volver a correr la suma no la duplica.
        $nombreQueYaEstaba = isset($this->nombresQueYaEstaban[$plano]);

        if ($codigo === null) {
            if ($nombreQueYaEstaba) {
                $this->contar('filas sin ID con un nombre que ya esta (no entran)');

                return false;
            }

            $this->nombresQueYaEstaban[$plano] = true;
        } else {
            $this->nuevas[$codigo] = true;
        }

        if ($nombreQueYaEstaba) {
            // Puede estar bien —el indice tiene sucursales con el mismo
            // nombre— pero si son cientos, el Access se renumero.
            $this->contar('empresas nuevas con un nombre que ya estaba');
        }

        $this->contar('empresas nuevas');

        return true;
    }

    private function empresas(string $carpeta, bool $aplicar): void
    {
        $tipoTelefono = $aplicar ? TipoMedio::firstOrCreate(['nombre' => 'Telefono']) : null;
        $tipoFax = $aplicar ? TipoMedio::firstOrCreate(['nombre' => 'Fax']) : null;

        foreach ($this->leer("{$carpeta}/empresas.csv") as $e) {
            $nombre = CatalogoViejo::texto($e['EMPRESA'] ?? null);

            if ($nombre === null) {
                continue;
            }

            $this->contar('empresas');

            if ($this->sumar && ! $this->hayQueCrear($e, $nombre, $aplicar)) {
                continue;
            }

            // El informe de la suma tambien cuenta lo que se agregaria a los
            // catalogos: un pais nuevo con nombre raro se ve antes de cargar.
            if (! $aplicar && ! $this->sumar) {
                continue;
            }

            if ($this->paises === []) {
                $this->cargarCatalogos();
            }

            [$paisId, $provinciaId, $localidadId, $cpEnElPais, $nota] = $this->ubicacion($e);
            $rubroId = $this->rubro(CatalogoViejo::texto($e['RUBRO'] ?? null));

            if (! $aplicar) {
                continue;
            }

            $empresa = Empresa::create([
                // El ID del Access: es la clave real del indice telefonico y
                // es por donde se cuelgan las 8048 cotizaciones.
                'codigo_indice' => trim((string) ($e['ID'] ?? '')) ?: null,
                'nombre' => $nombre,
                'direccion' => CatalogoViejo::texto($e['DIRECCION'] ?? null),
                'localidad_id' => $localidadId,
                'provincia_id' => $provinciaId,
                'pais_id' => $paisId,
                'codigo_postal' => $this->codigoPostal($e['CODPOSTAL'] ?? null) ?? $cpEnElPais,
                'rubro_id' => $rubroId,
                'observacion_general' => $this->observacion($e, $nota),
                'activa' => true,
            ]);

            $relacion = CatalogoViejo::RELACIONES[trim($e['RELAC'] ?? '')] ?? null;

            if ($relacion) {
                EmpresaRelacion::create([
                    'empresa_id' => $empresa->id,
                    'relacion' => $relacion,
                    'activa' => true,
                ]);
            }

            $this->contactoYTelefonos($empresa, $e, $tipoTelefono, $tipoFax);
            $this->guardarOriginal('empresas.csv', $e);

            if (isset($this->compartidos[$empresa->codigo_indice])) {
                $this->compartidos[$empresa->codigo_indice][2] = $empresa->id;
            }
        }

        $this->guardarOriginal(null);
    }

    /**
     * La fila del Access que dio una ficha o una cotizacion, para "Antes y ahora".
     *
     * Las de septiembre las guardo migracion:guardar-originales. Las que entran
     * con --sumar se guardan aca, tal como se leyeron, con la huella de la
     * cotizacion para que la pantalla la encuentre sin depender del orden.
     * Sin archivo, guarda lo que quedo pendiente.
     */
    private function guardarOriginal(?string $archivo, array $fila = [], ?string $clave = null): void
    {
        if (! $this->sumar || ! $this->aplicar) {
            return;
        }

        if ($archivo !== null) {
            $this->originalesNuevos[] = [
                'archivo' => $archivo,
                'fila' => $this->proximaFila[$archivo]++,
                'clave' => $clave,
                'datos' => json_encode($fila, JSON_UNESCAPED_UNICODE),
            ];
        }

        // De a tandas: un insert con miles de filas se pasa del tamaño de consulta.
        if ($this->originalesNuevos !== [] && ($archivo === null || count($this->originalesNuevos) >= 400)) {
            DB::table('migracion_originales')->insert($this->originalesNuevos);
            $this->originalesNuevos = [];
        }
    }

    /**
     * Lo que el Access guardaba suelto y aca va a la ficha.
     *
     * TELEX y TELEDISC no tenian destino y se perdian. TELEDISC es la
     * caracteristica telefonica de 284 fichas: no se pega al numero —serian
     * numeros de antes de 1999 y quedarian mal marcados— pero se deja escrita.
     */
    private function observacion(array $e, ?string $nota = null): ?string
    {
        $partes = array_filter([
            $nota,
            CatalogoViejo::texto($e['OBSERV'] ?? null),
            filled($e['HORARIOS'] ?? null) ? 'Horarios: '.CatalogoViejo::texto($e['HORARIOS']) : null,
            filled($e['TELEDISC'] ?? null) ? 'Caracteristica telefonica: '.CatalogoViejo::texto($e['TELEDISC']) : null,
            filled($e['TELEX'] ?? null) ? 'Telex: '.CatalogoViejo::texto($e['TELEX']) : null,
        ]);

        return $partes ? implode("\n", $partes) : null;
    }

    /** El Access guardaba el CP como numero: el 0 es "no lo cargaron". */
    private function codigoPostal(mixed $cp): ?string
    {
        $limpio = CatalogoViejo::texto((string) $cp);

        return ($limpio === null || (float) $limpio <= 0) ? null : $limpio;
    }

    private function contactoYTelefonos(Empresa $empresa, array $e, ?TipoMedio $tel, ?TipoMedio $fax): void
    {
        $quien = CatalogoViejo::texto($e['RESPONS'] ?? null);

        $contacto = Contacto::create([
            'empresa_id' => $empresa->id,
            'nombre' => $quien ?? 'Sin contacto',
            'principal' => true,
            'activo' => true,
        ]);

        $primero = true;

        foreach (['TELEF1', 'TELEF2', 'TELEF3', 'TELEF4', 'TELEF5'] as $campo) {
            // telefono() y no texto(): "-    -" es la mascara del formulario
            // sin llenar, no un numero. En el archivo real son 3667 de 4830.
            $numero = CatalogoViejo::telefono($e[$campo] ?? null);

            if ($numero === null) {
                $this->contar('telefonos vacios descartados');

                continue;
            }

            ContactoMedio::create([
                'contacto_id' => $contacto->id,
                'tipo_medio_id' => $tel->id,
                'valor' => $numero,
                'principal' => $primero,
            ]);

            $primero = false;
            $this->contar('telefonos');
        }

        if ($numero = CatalogoViejo::telefono($e['FAX'] ?? null)) {
            ContactoMedio::create([
                'contacto_id' => $contacto->id,
                'tipo_medio_id' => $fax->id,
                'valor' => $numero,
                'principal' => false,
            ]);
            $this->contar('faxes');
        }
    }

    /**
     * Los catalogos de ubicacion y rubros, por nombre plano.
     *
     * Se leen una vez: son unos cientos de filas y el indice completo son
     * miles de fichas que los consultan.
     */
    private function cargarCatalogos(): void
    {
        $paisDe = [];

        foreach (Pais::all(['id', 'nombre']) as $p) {
            $paisDe[$p->id] = CatalogoViejo::plano($p->nombre);
            $this->paises[$paisDe[$p->id]] ??= $p->id;
        }

        $provinciaDe = [];

        foreach (Provincia::all(['id', 'nombre', 'pais_id']) as $p) {
            $provinciaDe[$p->id] = ($paisDe[$p->pais_id] ?? '').'|'.CatalogoViejo::plano($p->nombre);
            $this->provincias[$provinciaDe[$p->id]] ??= $p->id;
        }

        foreach (Localidad::all(['id', 'nombre', 'provincia_id']) as $l) {
            if (! isset($provinciaDe[$l->provincia_id])) {
                continue;
            }

            $clave = $provinciaDe[$l->provincia_id].'|'.CatalogoViejo::plano($l->nombre);
            $this->localidades[$clave] ??= $l->id;
            $pais = strstr($provinciaDe[$l->provincia_id], '|', true);
            $this->localidadesDelPais[$pais.'|'.CatalogoViejo::plano($l->nombre)][$clave] = [$this->localidades[$clave], $l->provincia_id];
        }

        foreach (Rubro::all(['id', 'nombre']) as $r) {
            $this->rubros[CatalogoViejo::plano($r->nombre)] ??= $r->id;
        }
    }

    /** El id de lo que ya esta en el catalogo, o lo crea. En el informe no crea: cuenta. */
    private function delCatalogo(array &$catalogo, string $clave, string $que, \Closure $crear): ?int
    {
        if (! array_key_exists($clave, $catalogo)) {
            $catalogo[$clave] = $this->aplicar ? $crear() : null;
            $this->contar($que);
        }

        return $catalogo[$clave];
    }

    /** Un codigo postal argentino: una letra, cuatro numeros y hasta tres letras (B1646GEL, B7608V). */
    private static function esCodigoPostal(?string $texto): bool
    {
        return (bool) preg_match('/^[A-Z]\d{4}[A-Z]{0,3}$/', CatalogoViejo::plano((string) $texto));
    }

    /** El nombre de la lista cuya forma escrita coincide, o null. */
    private static function deLaLista(array $lista, string $plano): ?string
    {
        foreach ($lista as $nombre => $formas) {
            if (in_array($plano, $formas, true)) {
                return $nombre;
            }
        }

        return null;
    }

    /**
     * El pais, la provincia que estaba en el lugar del pais y la nota.
     *
     * "USA 07054" y "MEXICO 7" son el pais con un numero pegado. "CORDOBA" en
     * el pais es la provincia cargada una columna corrida. Lo demas no se
     * adivina: Argentina, con lo que decia escrito en la ficha.
     *
     * @return array{0: string, 1: ?string, 2: ?string} pais, provincia, nota
     */
    private function paisDe(?string $crudo): array
    {
        $plano = CatalogoViejo::plano((string) $crudo);

        if ($plano === '' || self::esCodigoPostal($crudo)) {
            return ['Argentina', null, null];
        }

        if ($pais = self::deLaLista(self::PAISES, $plano) ?? self::deLaLista(self::PAISES, (string) preg_replace('/\d+$/', '', $plano))) {
            return [$pais, null, null];
        }

        if (self::provinciaArgentina($crudo) !== null) {
            return ['Argentina', $crudo, null];
        }

        $this->sinReconocer['pais'][$crudo] = ($this->sinReconocer['pais'][$crudo] ?? 0) + 1;
        $this->contar('paises que no son un pais: Argentina, con lo que decia escrito en la ficha');

        return ['Argentina', null, "Pais en el indice viejo: {$crudo}."];
    }

    /** Lo que se escribe delante o detras del nombre sin cambiar de que provincia se habla. */
    private const DELANTE_DE_LA_PROVINCIA = ['PROVINCIADE', 'PROVINCIA', 'PCIADE', 'PCIA', 'PROVDE', 'PROV'];

    private const DETRAS_DE_LA_PROVINCIA = ['', 'ARGENTINA', 'RA', 'PROVINCIA', 'PCIA', 'PROV'];

    /** "BUENOS AIRES CAPITAL FEDERAL" es la ciudad, no la provincia. */
    private const ES_LA_CIUDAD = ['CAPITALFEDERAL', 'CAPITAL', 'CAPFED', 'CABA', 'CIUDAD', 'CF'];

    /**
     * La provincia argentina que nombra el texto, y si lo nombra sin dudas.
     *
     * Sin dudas: tal cual, con una abreviatura conocida o con "Pcia. de"
     * delante. Con algo mas pegado —"CORDOBA CAPITAL", "BUENOS AIRES CAPITAL
     * FEDERAL"— la provincia es esa, pero lo escrito queda en la nota porque
     * dice mas. Cualquier otra cosa detras no es una provincia argentina:
     * "SANTA CRUZ DE LA SIERRA" es Bolivia, "SAN LUIS POTOSI" es Mexico.
     *
     * @return array{0: string, 1: bool}|null provincia, sin dudas
     */
    private static function provinciaArgentina(?string $texto): ?array
    {
        $plano = CatalogoViejo::plano((string) $texto);

        foreach (self::DELANTE_DE_LA_PROVINCIA as $delante) {
            if (str_starts_with($plano, $delante) && strlen($plano) > strlen($delante)) {
                $plano = substr($plano, strlen($delante));

                break;
            }
        }

        if ($plano === '') {
            return null;
        }

        if ($provincia = self::deLaLista(self::PROVINCIAS_ARGENTINAS, $plano)) {
            return [$provincia, true];
        }

        foreach (array_keys(self::PROVINCIAS_ARGENTINAS) as $provincia) {
            $nombre = CatalogoViejo::plano($provincia);

            if (! str_starts_with($plano, $nombre)) {
                continue;
            }

            $resto = (string) preg_replace('/\d+$/', '', substr($plano, strlen($nombre)));

            if ($provincia === 'Buenos Aires' && in_array($resto, self::ES_LA_CIUDAD, true)) {
                return ['Ciudad Autónoma de Buenos Aires', false];
            }

            if (in_array($resto, self::DETRAS_DE_LA_PROVINCIA, true)) {
                return [$provincia, true];
            }

            if (in_array($resto, ['CAPITAL', 'CIUDAD'], true)) {
                return [$provincia, false];
            }
        }

        // "BUENSO AIRES", "SNATA FE", "MENDONZA": una o dos letras cambiadas en
        // un nombre largo, y una sola provincia a esa distancia.
        if (strlen($plano) >= 6) {
            $cerca = [];

            foreach (self::PROVINCIAS_ARGENTINAS as $provincia => $formas) {
                foreach ($formas as $forma) {
                    if (strlen($forma) >= 6 && ($d = levenshtein($plano, $forma)) <= 2) {
                        $cerca[$provincia] = min($cerca[$provincia] ?? 2, $d);
                    }
                }
            }

            if ($cerca !== [] && count(array_keys($cerca, min($cerca), true)) === 1) {
                return [array_search(min($cerca), $cerca, true), false];
            }
        }

        return null;
    }

    /**
     * Pais, provincia y localidad de la ficha, como los dejo la limpieza.
     *
     *  · "B1646GEL" en el pais o en la provincia es un codigo postal cargado
     *    una columna corrida: va al CP.
     *  · En Argentina solo entran las 24 provincias. Lo que no se reconoce
     *    no se adivina: queda escrito en la ficha, y la localidad tampoco se
     *    deduce de otra provincia.
     *  · Sin provincia escrita, "CAPITAL FEDERAL" o "CORDOBA" en la localidad
     *    dicen la provincia. Si no, y en el pais hay una sola localidad con ese
     *    nombre en el catalogo que ya estaba, es esa. Si no, el nombre queda
     *    escrito en la ficha para elegirla a mano.
     *
     * @return array{0: ?int, 1: ?int, 2: ?int, 3: ?string, 4: ?string} pais, provincia, localidad, CP, nota
     */
    private function ubicacion(array $e): array
    {
        $paisCrudo = CatalogoViejo::texto($e['PAIS'] ?? null);
        $crudaProvincia = CatalogoViejo::texto($e['PROVINCIA'] ?? null);

        // "CHILE" en la provincia y nada en el pais: el pais, una columna corrida.
        $paisEnLaProvincia = self::deLaLista(self::PAISES, CatalogoViejo::plano((string) $crudaProvincia));

        if ($paisEnLaProvincia !== null && CatalogoViejo::plano((string) $paisCrudo) === '') {
            [$paisCrudo, $crudaProvincia] = [$crudaProvincia, null];
        } elseif ($paisEnLaProvincia === 'Argentina') {
            $crudaProvincia = null;
        }

        $cp = self::esCodigoPostal($paisCrudo) ? mb_strtoupper($paisCrudo) : null;
        [$nombrePais, $provinciaEnElPais, $notaPais] = $this->paisDe($paisCrudo);
        $notas = array_filter([$notaPais]);
        $pais = CatalogoViejo::plano($nombrePais);
        $paisId = $this->delCatalogo($this->paises, $pais, "pais nuevo: {$nombrePais}",
            fn () => Pais::create(['nombre' => $nombrePais, 'activo' => true])->id);

        // "-", "Sin determinar" o un codigo postal no son una provincia.
        $planoProvincia = CatalogoViejo::plano((string) $crudaProvincia);

        if (self::esCodigoPostal($crudaProvincia)) {
            $cp ??= mb_strtoupper($crudaProvincia);
        }

        $sinProvincia = $planoProvincia === '' || $planoProvincia === 'SINDETERMINAR' || self::esCodigoPostal($crudaProvincia);
        $nombreProvincia = null;
        $noSeReconocio = false;

        if ($nombrePais !== 'Argentina') {
            $nombreProvincia = $sinProvincia ? null : $crudaProvincia;
        } else {
            $deLaColumna = $sinProvincia ? null : self::provinciaArgentina($crudaProvincia);
            $delPais = $provinciaEnElPais !== null ? self::provinciaArgentina($provinciaEnElPais) : null;

            // "CAPITAL" a secas no le gana a la provincia escrita en el pais.
            if ($deLaColumna && $delPais && $planoProvincia === 'CAPITAL') {
                $deLaColumna = null;
            }

            $noSeReconocio = ! $sinProvincia && $deLaColumna === null;
            [$nombreProvincia, $sinDudas] = $deLaColumna ?? $delPais ?? [null, true];

            if ($noSeReconocio) {
                $this->sinReconocer['provincia'][$crudaProvincia] = ($this->sinReconocer['provincia'][$crudaProvincia] ?? 0) + 1;
                $this->contar('provincias que no se reconocen: quedan escritas en la ficha');
                $notas[] = $nombreProvincia === null
                    ? "Provincia en el indice viejo: {$crudaProvincia} (falta elegirla en Modificar)."
                    : "Provincia en el indice viejo: {$crudaProvincia}.";
            } elseif ($nombreProvincia !== null && ! $sinDudas) {
                $notas[] = 'Provincia en el indice viejo: '.($deLaColumna ? $crudaProvincia : $provinciaEnElPais).'.';
            }
        }

        $crudaLocalidad = CatalogoViejo::texto($e['LOCALIDAD'] ?? null);
        $planoLocalidad = CatalogoViejo::plano((string) $crudaLocalidad);

        // "CAPITAL FEDERAL" o "MENDOZA" en la localidad dicen la provincia.
        // "BUENOS AIRES" no: puede ser la ciudad o la provincia.
        if ($nombreProvincia === null && ! $noSeReconocio && $notaPais === null && $nombrePais === 'Argentina') {
            $porLaLocalidad = self::deLaLista(self::PROVINCIAS_ARGENTINAS, $planoLocalidad);

            if ($porLaLocalidad !== null && $porLaLocalidad !== 'Buenos Aires' && $planoLocalidad !== 'CAPITAL') {
                $nombreProvincia = $porLaLocalidad;
                $this->contar('provincias sacadas de la localidad (CAPITAL FEDERAL, MENDOZA...)');
            }
        }

        $provincia = null;
        $provinciaId = null;

        if ($nombreProvincia !== null) {
            $provincia = $pais.'|'.CatalogoViejo::plano($nombreProvincia);
            $provinciaId = $this->delCatalogo($this->provincias, $provincia, 'provincias nuevas',
                fn () => Provincia::create(['nombre' => $nombreProvincia, 'pais_id' => $paisId, 'activo' => true])->id);
        }

        if ($crudaLocalidad === null) {
            return [$paisId, $provinciaId, null, $cp, ($notas ? implode("\n", $notas) : null)];
        }

        if ($provincia !== null) {
            $clave = $provincia.'|'.$planoLocalidad;
            $localidadId = $this->delCatalogo($this->localidades, $clave, 'localidades nuevas',
                fn () => Localidad::create(['nombre' => $crudaLocalidad, 'provincia_id' => $provinciaId, 'activo' => true])->id);

            return [$paisId, $provinciaId, $localidadId, $cp, ($notas ? implode("\n", $notas) : null)];
        }

        /*
          Solo si la provincia vino vacia y el pais es de verdad. Con una
          provincia escrita que no se reconocio, deducirla de la localidad
          ponia otra: "PCIA. DE CBA" con SANTA ROSA terminaba en La Pampa. Las
          candidatas salen del catalogo que habia al empezar, asi el resultado
          no depende del orden de las filas.
        */
        $candidatas = $noSeReconocio || $notaPais !== null ? [] : ($this->localidadesDelPais[$pais.'|'.$planoLocalidad] ?? []);

        if (count($candidatas) === 1) {
            $this->contar('localidades sin provincia reconocidas');
            [$localidadId, $provinciaId] = reset($candidatas);

            return [$paisId, $provinciaId, $localidadId, $cp, ($notas ? implode("\n", $notas) : null)];
        }

        $this->contar('localidades sin provincia: quedan escritas en la ficha');
        $notas[] = "Localidad en el indice viejo: {$crudaLocalidad} (falta elegirla en Modificar).";

        return [$paisId, null, null, $cp, ($notas ? implode("\n", $notas) : null)];
    }

    /** El rubro, sin repetir uno que ya esta escrito de otra forma. "??" no es un rubro. */
    private function rubro(?string $nombre): ?int
    {
        $plano = CatalogoViejo::plano((string) $nombre);

        return $plano === '' ? null : $this->delCatalogo($this->rubros, $plano, 'rubros nuevos',
            fn () => Rubro::create(['nombre' => $nombre, 'activo' => true])->id);
    }

    /**
     * Las 8048 filas historicas del sistema anterior.
     *
     * Cada fila es una pantalla de cinco renglones de texto con su casilla de
     * precio: la agrupacion en articulos la hace CatalogoViejo::renglones(),
     * que es donde esta explicada y medida.
     *
     * Aca se decide que es cada fila:
     *  · si algun renglon tiene precio, es una COTIZACION y sus articulos son
     *    las lineas;
     *  · si ninguno lo tiene, es una OBSERVACION sobre el cliente — "interesados
     *    en alambre de nitinol, actualmente lo importan de USA". Son 899 y
     *    hasta ahora entraban como cotizaciones confirmadas sin un solo precio.
     *
     * El texto original de los cinco renglones se guarda entero y tal cual en
     * consultas.texto. Pase lo que pase con la agrupacion, lo que escribio el
     * vendedor esta y se puede volver a leer.
     */
    private function cotizaciones(string $carpeta, bool $aplicar): void
    {
        $porCodigo = $aplicar ? Empresa::whereNotNull('codigo_indice')->pluck('id', 'codigo_indice') : collect();
        $porNombre = $aplicar ? Empresa::pluck('id', 'nombre') : collect();
        $monedas = $aplicar ? Moneda::pluck('id', 'nombre') : collect();
        // Solo las vivas: C/U, M y TON se apagaron por repetidas y sus lineas
        // pasaron a UN, MT y TN.
        $unidades = $aplicar ? Unidad::where('activo', true)->pluck('id', 'codigo') : collect();

        foreach (self::UNIDADES_REPETIDAS as $seFue => $queda) {
            if (! isset($unidades[$seFue]) && isset($unidades[$queda])) {
                $unidades[$seFue] = $unidades[$queda];
            }
        }

        $usuario = $aplicar ? User::orderBy('id')->first() : null;

        foreach ($this->leer("{$carpeta}/cotizaciones.csv") as $c) {
            $nombre = CatalogoViejo::texto($c['EMPRESA'] ?? null);
            $fecha = CatalogoViejo::fecha($c['FECHA'] ?? null);
            $codigo = trim((string) ($c['ID'] ?? '')) ?: null;

            if ($nombre === null) {
                /*
                  Sin nombre: la que no trae nada es un resto de la conversion a
                  Access (en el indice completo, 4.337). La que trae texto o
                  precio y el ID de su empresa se cuelga por el ID.
                */
                if ($codigo === null || ($this->textoOriginal($c) === null && blank($c['NOTA'] ?? null))) {
                    $this->contar('filas vacias del Access (no entran)');

                    continue;
                }

                if (! isset($this->yaEstaban[$codigo]) && ! isset($this->nuevas[$codigo]) && ! isset($this->compartidos[$codigo])
                    && ! ($aplicar && isset($porCodigo[$codigo]))) {
                    $this->contar('sin nombre y con un ID que no esta (no entran)');

                    continue;
                }

                $this->contar('sin nombre de empresa: se cuelgan por el ID');
            }

            if ($codigo !== null && isset($this->conflictos[$codigo])) {
                $this->contar('cotizaciones de esas fichas (no entran)');

                continue;
            }

            [$huella] = $this->huella($c, $codigo, $fecha);

            if ($this->sumar && ! $this->cotizacionNueva($c, $codigo, $fecha)) {
                continue;
            }

            ['items' => $items, 'sueltos' => $sueltos] = CatalogoViejo::renglones($c);

            if (preg_match('/^\d{8}$/', trim((string) ($c['FECHA'] ?? ''))) && $fecha !== null) {
                $this->contar(str_starts_with(trim($c['FECHA']), substr($fecha, 0, 4))
                    ? 'fechas de 8 cifras leidas como año-mes-dia'
                    : 'fechas de 8 cifras leidas como dia-mes-año');
            }

            if ($fecha === null) {
                $this->contar('filas sin fecha usable');
            } elseif (CatalogoViejo::fechaDudosa($c['FECHA'] ?? null)) {
                /*
                  La fecha imposible no se guarda como si fuera buena: una
                  cotizacion fechada en 2424 se ordena delante de la del mes
                  pasado en la ficha del cliente. Queda como "sin fecha", que
                  es lo que realmente se sabe, con el valor original escrito
                  en la nota para que se pueda corregir a mano.
                */
                $this->contar('con fecha imposible: se marcan para revisar');
                $fecha = null;
            }

            $this->contar($items === [] ? 'observaciones sobre el cliente' : 'cotizaciones');
            $this->contar('lineas de cotizacion', count($items));
            $this->contar('renglones sin precio guardados como nota', count($sueltos));

            if (! $aplicar) {
                // El informe de la suma cuenta tambien las fichas que
                // apareceran por una cotizacion de una letra que no vino.
                if ($this->sumar && $codigo !== null && ! isset($this->yaEstaban[$codigo]) && ! isset($this->nuevas[$codigo])) {
                    $this->nuevas[$codigo] = true;
                    $this->contar('empresas creadas desde una cotizacion');
                }

                continue;
            }

            /*
              Por el ID del Access, que es la clave real: 8044 de las 8048
              apuntan a un ID que existe y ningun ID aparece con dos nombres
              distintos. Antes se buscaba por nombre y los 66 nombres repetidos
              del indice colapsaban el historial en una sola ficha.
            */
            $empresaId = ($codigo !== null ? ($porCodigo[$codigo] ?? null) : null)
                ?? ($porNombre[$nombre] ?? null);

            // El ID que el sistema viejo uso para dos empresas: va a la que nombra.
            if ($codigo !== null && isset($this->compartidos[$codigo])) {
                [$suNombre, $suFicha] = $this->compartidos[$codigo];
                $empresaId = $nombre !== null && CatalogoViejo::plano($nombre) === $suNombre
                    ? $suFicha
                    : ($this->compartidos[$codigo][2] ?? $empresaId);
            }

            if ($empresaId === null && $nombre === null) {
                $this->contar('sin nombre y con un ID que no esta (no entran)');

                continue;
            }

            if ($empresaId === null) {
                // Esta en una letra del indice que no vino: se crea con lo que
                // trae la cotizacion para no perder el historial.
                $empresaId = Empresa::create([
                    'nombre' => $nombre,
                    'codigo_indice' => $codigo,
                    'activa' => true,
                ])->id;
                $porNombre[$nombre] = $empresaId;

                if ($codigo !== null) {
                    $porCodigo[$codigo] = $empresaId;
                }

                $this->contar('empresas creadas desde una cotizacion');
            }

            [$moneda, $reconocida] = CatalogoViejo::moneda($c['MON'] ?? null);

            if (! $reconocida) {
                $this->contar('con moneda historica (austral u otra): no se convierte');
            }

            $consulta = Consulta::create([
                'empresa_id' => $empresaId,
                'tipo' => $items === [] ? 'Observacion' : 'Cotizacion',
                'fecha' => $fecha ?? '1900-01-01',
                'estado' => $items === [] ? 'Sin cotizar' : 'Confirmada',
                'usuario_id' => $usuario?->id,
                'moneda_id' => $monedas[$moneda] ?? null,
                // El ID de la fila del Access: para poder volver al origen.
                'id_sistema' => $codigo,
                'origen_clave' => $huella,
                /*
                  Lo que salio, salio: sin esto la cotizacion historica queda
                  como borrador editable y "Emitir" le daria un numero nuevo.
                  Es lo que hicieron con las 8048 revisiones_de_la_cotizacion y
                  hora_de_argentina. emitida_el es TIMESTAMP y no llega antes
                  de 1970: la fecha imposible (1900-01-01) va al primer dia
                  que entra.
                */
                'emitida_el' => $items === [] ? null : max($fecha ?? '1900-01-01', '1970-01-02'),
                'tipo_cambio' => filled($c['DOLAR'] ?? null) && (float) $c['DOLAR'] > 0
                    ? (float) $c['DOLAR']
                    : null,
                'nro_factura' => filled($c['NROFACT'] ?? null) && (float) $c['NROFACT'] > 0
                    ? (string) (int) $c['NROFACT']
                    : null,
                // Los cinco renglones como estaban, sin agrupar ni interpretar.
                'texto' => $this->textoOriginal($c),
                'nota' => $this->notaHistorica($c, $reconocida, $sueltos),
                'validez_dias' => 0,
            ]);

            $this->guardarOriginal('cotizaciones.csv', $c, $huella);

            foreach ($items as $orden => $item) {
                ConsultaLinea::create([
                    'consulta_id' => $consulta->id,
                    'orden' => $orden + 1,
                    'descripcion' => $item['descripcion'],
                    // La cantidad solo si estaba escrita. Sin ella no hay
                    // importe: el precio unitario queda igual, que es el dato
                    // que si se cotizo.
                    'cantidad' => $item['cantidad'],
                    'unidad_venta_id' => $item['unidad'] ? ($unidades[$item['unidad']] ?? null) : null,
                    'precio_unitario' => $item['precio'],
                    'importe' => $item['cantidad'] !== null
                        ? round($item['cantidad'] * $item['precio'], 2)
                        : null,
                    'igual_a_lo_pedido' => true,
                ]);

                if ($item['cantidad'] !== null) {
                    $this->contar('lineas con cantidad leida del texto');
                }
            }
        }

        $this->guardarOriginal(null);
    }

    /**
     * Si la cotizacion falta en el sistema.
     *
     * El Access no le da un numero propio a cada cotizacion: la reconoce
     * quien, cuando y que se cotizo. Se compara con lo mismo que guardo la
     * importacion —el ID de la empresa, la fecha como quedo guardada y los
     * cinco renglones tal cual—, asi lo que ya entro se reconoce siempre.
     *
     * Si al Access le corrigieron el texto de una cotizacion vieja, ya no se
     * reconoce y entraria otra vez: por eso el informe cuenta aparte las
     * nuevas de empresas que ya estaban con fecha anterior a la ultima
     * importada. Ese numero tiene que ser chico antes de aplicar.
     */
    private function cotizacionNueva(array $c, ?string $codigo, ?string $fecha): bool
    {
        [$clave, $guardada] = $this->huella($c, $codigo, $fecha);

        if (isset($this->cotizacionesQueYaEstaban[$clave])) {
            $this->contar('cotizaciones que ya estaban (o repetidas en el Access)');

            return false;
        }

        $this->cotizacionesQueYaEstaban[$clave] = true;

        if ($codigo !== null && isset($this->yaEstaban[$codigo])) {
            $this->contar('cotizaciones nuevas de empresas que ya estaban');

            if ($guardada <= $this->ultimaImportada) {
                $this->contar('  de esas, con fecha anterior a la ultima importada (mirar)');
                $this->anotarDiferencia($c, $codigo, $fecha);
            }
        }

        return true;
    }

    /**
     * En que se diferencia del de septiembre una cotizacion que no se reconocio.
     *
     * Si la empresa tiene una cotizacion de septiembre el mismo dia, es casi
     * seguro la misma escrita distinto. Se anotan los caracteres que estan de
     * un solo lado —un simbolo de diametro con otra codificacion, un espacio
     * de mas—: sin mostrar el texto, dice que hay que emparejar.
     */
    private function anotarDiferencia(array $c, ?string $codigo, ?string $fecha): void
    {
        $candidatas = $this->deSeptiembre[$codigo.'|'.($fecha ?? '')] ?? [];

        if ($candidatas === []) {
            $this->contar('    (mirar) sin una de septiembre de la misma empresa y fecha');

            return;
        }

        $nuevo = (string) $this->textoOriginal($c);
        $parecida = collect($candidatas)->sortByDesc(fn ($t) => similar_text($t, $nuevo))->first();
        $deUno = array_count_values(mb_str_split($nuevo));
        $deOtro = array_count_values(mb_str_split($parecida));

        foreach ([['nuevo', $deUno, $deOtro], ['septiembre', $deOtro, $deUno]] as [$lado, $estos, $aquellos]) {
            foreach ($estos as $caracter => $veces) {
                if ($veces > ($aquellos[$caracter] ?? 0)) {
                    $this->caracteresDistintos[$lado][$caracter] = ($this->caracteresDistintos[$lado][$caracter] ?? 0) + 1;
                }
            }
        }
    }

    /**
     * La huella de una fila del Access: empresa, fecha como se guarda y los
     * cinco renglones. Es la misma la calcule quien la calcule.
     *
     * @return array{0: string, 1: string} huella, fecha guardada
     */
    private function huella(array $c, ?string $codigo, ?string $fecha): array
    {
        $guardada = ($fecha === null || CatalogoViejo::fechaDudosa($c['FECHA'] ?? null)) ? '1900-01-01' : $fecha;

        /*
          La huella lleva la fecha como esta escrita, no como se guarda: una
          fecha "del futuro" se guarda 1900-01-01 hoy y la suya mañana deja de
          serlo. Con la guardada, la misma fila tendria otra huella segun el
          dia en que se corre, y al volver a sumar entraria de nuevo.
        */
        return [md5($codigo.'|'.($fecha ?? '').'|'.md5((string) $this->textoOriginal($c))), $guardada];
    }

    /** Los cinco renglones como los dejo el sistema anterior. */
    private function textoOriginal(array $c): ?string
    {
        $renglones = [];

        for ($i = 1; $i <= 5; $i++) {
            if ($t = CatalogoViejo::texto($c["PE{$i}"] ?? null)) {
                $precio = (float) ($c["PR{$i}"] ?? 0);
                $renglones[] = $precio > 0 ? $t.'   '.$precio : $t;
            }
        }

        return $renglones ? implode("\n", $renglones) : null;
    }

    /** @param list<string> $sueltos renglones sin precio: plazos, aclaraciones */
    private function notaHistorica(array $c, bool $monedaReconocida, array $sueltos = []): string
    {
        $partes = [self::NOTA_IMPORTADA];

        // Lo que quedo despues del ultimo precio: plazo de entrega, condicion
        // de pago, aclaraciones. No son articulos pero tampoco se tiran.
        foreach ($sueltos as $suelto) {
            $partes[] = $suelto;
        }

        if (CatalogoViejo::fechaDudosa($c['FECHA'] ?? null)) {
            $partes[] = 'REVISAR LA FECHA: en el sistema anterior figura '
                .trim($c['FECHA']).', que no puede ser.';
        } elseif (filled($c['FECHA'] ?? null) && CatalogoViejo::fecha($c['FECHA']) === null) {
            // Que no se pierda: "999999" no se puede leer, pero alguien lo escribio.
            $partes[] = 'REVISAR LA FECHA: en el sistema anterior figura '
                .trim($c['FECHA']).', que no se puede leer.';
        }

        if ($nota = CatalogoViejo::texto($c['NOTA'] ?? null)) {
            $partes[] = $nota;
        }

        if ($quien = CatalogoViejo::texto($c['CRESP'] ?? null)) {
            $partes[] = 'Responsable: '.$quien;
        }

        if (! $monedaReconocida && filled($c['MON'] ?? null)) {
            // No se convierte: son valores de otra epoca.
            $partes[] = 'Moneda original: '.trim($c['MON']);
        }

        if (filled($c['DOLAR'] ?? null) && (float) $c['DOLAR'] > 0) {
            $partes[] = 'Tipo de cambio del dia: '.$c['DOLAR'];
        }

        return implode("\n", $partes);
    }

    /**
     * Fila por fila, sin cargar el archivo entero: el indice completo son
     * cientos de miles de cotizaciones.
     *
     * @return \Generator<int, array<string, string>>
     */
    private function leer(string $ruta): \Generator
    {
        $f = fopen($ruta, 'r');
        // Sin escape: el Access escribe las comillas duplicandolas, como manda
        // el formato. Los archivos no tienen una sola barra invertida.
        $cabecera = fgetcsv($f, 0, ',', '"', '');
        $malas = 0;

        /*
          Las cotizaciones del indice completo salieron enteras de la misma
          conversion de DOS: se traducen todas. En las empresas hay paises ya
          corregidos a mano ("ESPAÑA"): ahi solo lo que muestra la marca.
        */
        $siempre = basename($ruta) === 'cotizaciones.csv';
        $traducir = fn (string $v) => CatalogoViejo::desdeDos($v, $siempre);

        while (($fila = fgetcsv($f, 0, ',', '"', '')) !== false) {
            if (count($fila) === count($cabecera)) {
                yield array_combine($cabecera, $this->desdeDos ? array_map($traducir, $fila) : $fila);
            } elseif ($fila !== [null]) {
                $malas++;
            }
        }

        fclose($f);

        // Que no se pierda callada. Se asigna y no se suma: la suma lee
        // empresas.csv dos veces.
        if ($malas > 0) {
            $this->conteo['filas de '.basename($ruta).' que no se pudieron leer'] = $malas;
        }
    }

    /**
     * El conteo, al final.
     *
     * Va en el handle y no en el destructor: la pantalla captura la salida
     * cuando el comando termina, y para entonces el destructor todavia no
     * corrio — el informe llegaba vacio.
     */
    private function resumenEnPantalla(): void
    {
        if ($this->conteo === []) {
            return;
        }

        $this->newLine();
        $this->table(['', 'filas'], collect($this->conteo)
            ->map(fn ($n, $q) => [$q, number_format($n, 0, ',', '.')])
            ->values()->all());

        // Los textos de pais y provincia que no se reconocieron, para sumar a
        // la lista los que si eran (no dicen nada de nadie: son lugares).
        foreach (['pais', 'provincia'] as $que) {
            if ($cuales = $this->sinReconocer[$que] ?? []) {
                arsort($cuales);
                $this->warn(ucfirst($que).' sin reconocer ('.count($cuales).' distintos): '.collect(array_slice($cuales, 0, 30, true))
                    ->map(fn ($n, $t) => "{$t} ({$n})")->implode(', '));
            }
        }

        // Los caracteres, no los textos: en cuantas "(mirar)" aparece cada uno de un solo lado.
        foreach (['nuevo' => 'Access nuevo', 'septiembre' => 'el de septiembre'] as $lado => $nombre) {
            if ($cuales = $this->caracteresDistintos[$lado] ?? []) {
                arsort($cuales);
                $this->warn("Caracteres que solo estan en {$nombre}: ".collect(array_slice($cuales, 0, 15, true))
                    ->map(fn ($n, $ch) => ($ch === ' ' ? 'espacio' : $ch).sprintf(' U+%04X', mb_ord((string) $ch))." ({$n})")->implode(', '));
            }
        }

        // Solo los ID, que no dicen nada de nadie: cada uno se busca en el
        // sistema por "ID del indice" y se compara con el Access a mano.
        if ($this->conflictos !== []) {
            $ids = array_keys($this->conflictos);
            $this->warn('Mismo ID y otro nombre: '.implode(', ', array_slice($ids, 0, 40))
                .(count($ids) > 40 ? ' y '.(count($ids) - 40).' mas' : ''));
        }
    }
}

/** Corta la transaccion del modo informe sin ensuciar la salida. */
class SoloInforme extends \RuntimeException {}

/** Los ID del Access nuevo no son los del que ya se importo: no se suma. */
class IdsQueNoCoinciden extends \RuntimeException {}
