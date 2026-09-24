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
use App\Models\Provincia;
use App\Models\TipoMedio;
use App\Models\Unidad;
use App\Models\User;
use App\Services\Migracion\CatalogoViejo;
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
 */
class ImportarSistemaViejo extends Command
{
    protected $signature = 'importar:sistema-viejo
        {--carpeta= : donde estan los CSV exportados del Access}
        {--aplicar : escribe en la base; sin esto solo informa}';

    protected $description = 'Importa los datos reales del sistema anterior';

    /** Donde cuelga una localidad cuya ficha no dice de que provincia es. */
    private const SIN_PROVINCIA = 'Sin determinar';

    /** @var array<string, int> */
    private array $conteo = [];

    private function contar(string $que, int $cuantos = 1): void
    {
        $this->conteo[$que] = ($this->conteo[$que] ?? 0) + $cuantos;
    }

    public function handle(): int
    {
        $carpeta = rtrim((string) $this->option('carpeta'), '/');
        $aplicar = (bool) $this->option('aplicar');

        foreach (['Materiales', 'FamiliaMaterial', 'Forma', 'Unidades', 'empresas', 'cotizaciones'] as $a) {
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
            if ($aplicar) {
                $this->sacarLoDeEjemplo();
            }

            $this->formas($carpeta, $aplicar);
            $this->unidades($carpeta, $aplicar);
            $this->materiales($carpeta, $aplicar);
            $this->empresas($carpeta, $aplicar);
            $this->cotizaciones($carpeta, $aplicar);

                // Deshace todo: el modo informe cuenta pero no escribe.
                if (! $aplicar) {
                    throw new SoloInforme;
                }
            });
        } catch (SoloInforme) {
            // Esperado.
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

            if (! $aplicar) {
                continue;
            }

            $pais = $this->pais(CatalogoViejo::texto($e['PAIS'] ?? null));
            $nombreProv = CatalogoViejo::texto($e['PROVINCIA'] ?? null);
            $provincia = $nombreProv ? $this->provincia($nombreProv, $pais) : null;

            /*
              La localidad necesita una provincia porque localidades.provincia_id
              es NOT NULL. 281 fichas traen localidad y no traen provincia, y se
              perdian enteras. Ahora cuelgan de una provincia "Sin determinar"
              del pais que corresponda: el dato se guarda, queda a la vista que
              falta ubicarlo, y la ficha NO se inventa una provincia.
            */
            $localidad = $this->localidad(
                CatalogoViejo::texto($e['LOCALIDAD'] ?? null),
                $provincia ?? $this->provincia(self::SIN_PROVINCIA, $pais),
            );

            $empresa = Empresa::create([
                // El ID del Access: es la clave real del indice telefonico y
                // es por donde se cuelgan las 8048 cotizaciones.
                'codigo_indice' => trim((string) ($e['ID'] ?? '')) ?: null,
                'nombre' => $nombre,
                'direccion' => CatalogoViejo::texto($e['DIRECCION'] ?? null),
                'localidad_id' => $localidad?->id,
                'provincia_id' => $provincia?->id,
                'pais_id' => $pais->id,
                'codigo_postal' => $this->codigoPostal($e['CODPOSTAL'] ?? null),
                'rubro_id' => $this->rubro(CatalogoViejo::texto($e['RUBRO'] ?? null))?->id,
                'observacion_general' => $this->observacion($e),
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
        }
    }

    /**
     * Lo que el Access guardaba suelto y aca va a la ficha.
     *
     * TELEX y TELEDISC no tenian destino y se perdian. TELEDISC es la
     * caracteristica telefonica de 284 fichas: no se pega al numero —serian
     * numeros de antes de 1999 y quedarian mal marcados— pero se deja escrita.
     */
    private function observacion(array $e): ?string
    {
        $partes = array_filter([
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

    private function rubro(?string $nombre): ?\App\Models\Rubro
    {
        return $nombre ? \App\Models\Rubro::firstOrCreate(['nombre' => $nombre], ['activo' => true]) : null;
    }

    /**
     * La provincia, colgada del pais que diga la ficha.
     *
     * El sistema viejo guardaba el pais aparte y solo en el 14% de las fichas.
     * Sin pais se asume Argentina, que es de donde es el 100% de las que si lo
     * tienen cargado salvo un puñado.
     */
    private function pais(?string $cruda): \App\Models\Pais
    {
        return \App\Models\Pais::firstOrCreate(
            ['nombre' => $this->nombreDePais($cruda)],
            ['activo' => true],
        );
    }

    private function provincia(string $nombre, \App\Models\Pais $pais): Provincia
    {
        return Provincia::firstOrCreate(
            ['nombre' => $nombre, 'pais_id' => $pais->id],
            ['activo' => true],
        );
    }

    private function nombreDePais(?string $cruda): string
    {
        return match (mb_strtoupper(trim((string) $cruda))) {
            '', 'ARG', 'ARGENTINA' => 'Argentina',
            default => ucfirst(mb_strtolower(trim((string) $cruda))),
        };
    }

    private function localidad(?string $nombre, Provincia $provincia): ?Localidad
    {
        if ($nombre === null) {
            return null;
        }

        return Localidad::firstOrCreate(
            ['nombre' => $nombre, 'provincia_id' => $provincia->id],
            ['activo' => true],
        );
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
        $unidades = $aplicar ? Unidad::pluck('id', 'codigo') : collect();
        $usuario = $aplicar ? User::orderBy('id')->first() : null;

        foreach ($this->leer("{$carpeta}/cotizaciones.csv") as $c) {
            $nombre = CatalogoViejo::texto($c['EMPRESA'] ?? null);
            $fecha = CatalogoViejo::fecha($c['FECHA'] ?? null);

            if ($nombre === null) {
                continue;
            }

            ['items' => $items, 'sueltos' => $sueltos] = CatalogoViejo::renglones($c);

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
                continue;
            }

            /*
              Por el ID del Access, que es la clave real: 8044 de las 8048
              apuntan a un ID que existe y ningun ID aparece con dos nombres
              distintos. Antes se buscaba por nombre y los 66 nombres repetidos
              del indice colapsaban el historial en una sola ficha.
            */
            $codigo = trim((string) ($c['ID'] ?? '')) ?: null;
            $empresaId = ($codigo !== null ? ($porCodigo[$codigo] ?? null) : null)
                ?? ($porNombre[$nombre] ?? null);

            if ($empresaId === null) {
                // Esta en una letra del indice que no vino: se crea con lo que
                // trae la cotizacion para no perder el historial.
                $empresaId = Empresa::create([
                    'nombre' => $nombre,
                    'codigo_indice' => $codigo,
                    'activa' => true,
                ])->id;
                $porNombre[$nombre] = $empresaId;
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
        $partes = ['Importada del sistema anterior.'];

        // Lo que quedo despues del ultimo precio: plazo de entrega, condicion
        // de pago, aclaraciones. No son articulos pero tampoco se tiran.
        foreach ($sueltos as $suelto) {
            $partes[] = $suelto;
        }

        if (CatalogoViejo::fechaDudosa($c['FECHA'] ?? null)) {
            $partes[] = 'REVISAR LA FECHA: en el sistema anterior figura '
                .trim($c['FECHA']).', que no puede ser.';
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

    /** @return array<int, array<string, string>> */
    private function leer(string $ruta): array
    {
        $f = fopen($ruta, 'r');
        // Sin escape: el Access escribe las comillas duplicandolas, como manda
        // el formato. Los archivos no tienen una sola barra invertida.
        $cabecera = fgetcsv($f, 0, ',', '"', '');
        $filas = [];

        while (($fila = fgetcsv($f, 0, ',', '"', '')) !== false) {
            if (count($fila) === count($cabecera)) {
                $filas[] = array_combine($cabecera, $fila);
            }
        }

        fclose($f);

        return $filas;
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
    }
}

/** Corta la transaccion del modo informe sin ensuciar la salida. */
class SoloInforme extends \RuntimeException {}
