<?php

namespace App\Services\Cordes;

use App\Http\Controllers\ReportesController;
use App\Models\CanoEstandar;
use App\Models\Consulta;
use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Material;
use App\Models\User;
use App\Services\Buscador\Buscador;
use App\Services\Buscador\Catalogo;
use App\Services\Buscador\Filtros;
use App\Services\Buscador\Periodo;
use App\Services\Buscador\ResolverDePedido;
use App\Services\Buscador\Texto;
use App\Services\CalculadoraDePeso;
use App\Services\EvaluadorDeFormulas;
use App\Services\Reportes\FiltrosDeReporte;
use App\Services\Reportes\Reporte;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Lo que Cordes IA puede CONSULTAR. Todo de solo lectura, todo con el mismo criterio:
 *
 *  · Respeta quién ve qué: sin permiso de importes no sale ni un precio (y por eso tampoco viaja a la IA).
 *  · Devuelve poco: un tope de filas y un tope de tamaño por respuesta. Si hay más, lo dice (`total`, `mostrando`).
 *  · Devuelve los datos ya redondeados y, cuando sirve para mostrar, una `tabla` lista: la pantalla la muestra
 *    tal cual salió, sin que la IA la reescriba (así no puede cambiarle un número).
 *  · Nunca falla en silencio: si no entiende un pedido, devuelve `error` con el motivo y, si puede, qué probar.
 *
 * Los textos que vienen de la base (notas, descripciones) son DATOS: la IA tiene la orden de no obedecerlos.
 */
final class Herramientas
{
    public const TOPE_FILAS = 15;

    public const TOPE_BYTES = 9000;

    private readonly Catalogo $catalogo;

    private readonly ResolverDePedido $resolver;

    private readonly Buscador $buscador;

    public function __construct(private readonly User $usuario, ?Catalogo $catalogo = null)
    {
        $this->catalogo = $catalogo ?? new Catalogo;
        $this->resolver = new ResolverDePedido($this->catalogo);
        $this->buscador = new Buscador($usuario);
    }

    /* ------------------------------------------------------------- definiciones */

    private const FILTROS = 'Filtros. Claves posibles: texto, empresa (nombre), empresa_id, relacion (Cliente/Proveedor/…), rubro, provincia, localidad, '
        .'activa (activas/inactivas), sin_cotizar_meses, condicion_iva, condicion_pago, tipo (Cotizacion/Pedido/Observacion), estado, quien (iniciales), moneda '
        .'(dolares/pesos/euros), periodo (hoy, ayer, esta_semana, semana_pasada, este_mes, mes_pasado, este_anio, anio_pasado), desde y hasta (YYYY-MM-DD), '
        .'compra (vendida/parcial/total), orden_de_compra, sin_respuesta (true), por_vencer_dias, material (nombre), material_id, forma (nombre), forma_id, '
        .'diametro_desde y diametro_hasta (mm), comprobante, tipo_comprobante, provincia_id, localidad_id, rubro_id, usuario_id, moneda_id.';

    /** @return list<array<string, mixed>> las herramientas, en el formato de la IA (OpenAI "tools") */
    public function definiciones(): array
    {
        $vistas = ['type' => 'string', 'enum' => Filtros::GRANOS, 'description' => 'empresas | consultas (cotizaciones y pedidos) | renglones (material cotizado, con precios) | comprobantes (facturas y remitos)'];
        $filtros = ['type' => 'object', 'description' => self::FILTROS, 'additionalProperties' => true];
        $f = fn (string $nombre, string $descripcion, array $propiedades = [], array $requeridas = []) => [
            'type' => 'function',
            'function' => [
                'name' => $nombre,
                'description' => $descripcion,
                'parameters' => ['type' => 'object', 'properties' => $propiedades ?: new \stdClass, 'required' => $requeridas],
            ],
        ];

        $herramientas = [
            $f('motor', 'El MOTOR del sistema: contesta con exactitud una sub-pregunta escrita como la diría una persona —también una COMPARACIÓN («cuánto más caro es A que B», «cuánto más pesa A que B»), una suma de piezas o dos preguntas en una—: un peso («cuánto pesa una barra de monel de 38,1 x 145 mm», '
                .'con piezas, pulgadas, largos aleatorios), un precio, una tabla de caños o schedule, la fórmula de una forma, los datos de un material, la ficha de una empresa, una conversión o un ranking. '
                .'Usa las mismas fórmulas y datos que el Buscador. Preferilo a armar vos las medidas. Si no entiende, devuelve entendio=false: usá entonces las herramientas finas. Si le falta un dato devuelve `faltan` con las opciones.',
                ['pregunta' => ['type' => 'string', 'description' => 'UNA sola sub-pregunta, completa y autosuficiente (material, forma y medidas incluidos)']], ['pregunta']),
            $f('buscar', 'Busca en el sistema y devuelve cuántas filas hay y las primeras. Usala para "qué empresas / cuántas cotizaciones / qué se cotizó / qué facturas".',
                ['vista' => $vistas, 'filtros' => $filtros, 'limite' => ['type' => 'integer', 'description' => 'Cuántas filas traer (máx. 15)']], ['vista']),
            $f('agrupar', 'Cuenta las filas de una búsqueda agrupándolas: por provincia, empresa, material, forma, estado, mes, año… Responde "dónde están", "quién más", "cómo viene por año".',
                ['vista' => $vistas, 'filtros' => $filtros, 'por' => ['type' => 'string', 'description' => 'empresas: provincia|localidad|rubro|activa. consultas: empresa|estado|tipo|mes|anio|usuario|moneda|provincia. renglones: empresa|material|forma|estado|mes|anio|moneda|provincia. comprobantes: tipo|empresa|mes|anio'],
                    'limite' => ['type' => 'integer']], ['vista', 'por']),
            $f('buscar_empresas', 'Busca empresas por nombre, CUIT, contacto o cualquier dato de la ficha. Usala para identificar de qué empresa hablan (y detectar si hay dos parecidas).',
                ['texto' => ['type' => 'string'], 'limite' => ['type' => 'integer']], ['texto']),
            $f('empresa', 'La ficha completa de UNA empresa: datos, contactos (con mail y teléfono), condiciones, y su actividad (cotizaciones, estados, materiales más cotizados, importes si hay permiso). Pasá el id (mejor) o el nombre.',
                ['id' => ['type' => 'integer'], 'nombre' => ['type' => 'string']]),
            $f('cotizacion', 'Una cotización o pedido puntual con sus renglones. Pasá el id, o el número («2026-0741»), o el N° de pedido del cliente.',
                ['id' => ['type' => 'integer'], 'numero' => ['type' => 'string']]),
            $f('material', 'Datos del catálogo de un material: familia, densidad, UNS, W.Nr, alias, cuántas veces se cotizó. Si no existe, sugiere los más parecidos.',
                ['texto' => ['type' => 'string']], ['texto']),
            $f('formas', 'Las formas (barra redonda, chapa, caño…) con sus medidas y la fórmula del volumen. Sin texto, trae todas.',
                ['texto' => ['type' => 'string']]),
            $f('canos', 'La tabla estándar de caños: diámetro exterior, pared y sección por diámetro nominal y schedule. Con `material` suma los kg por metro.',
                ['nominal' => ['type' => 'string', 'description' => 'Ej: 2, 2 1/2, 1/2'], 'dn' => ['type' => 'integer', 'description' => 'Diámetro nominal en mm (DN50 = 2 pulgadas)'], 'schedule' => ['type' => 'string', 'description' => 'Ej: 40, 80, STD, XS, 10S'],
                    'material' => ['type' => 'string']]),
            $f('calcular_peso', 'Calcula el peso con la fórmula y la densidad del sistema. Es la ÚNICA fuente válida de un peso. Primero identificá la forma (`formas`) para saber las claves de las medidas.',
                ['material' => ['type' => 'string'], 'forma' => ['type' => 'string'], 'piezas' => ['type' => 'number'],
                    'medidas' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['clave' => ['type' => 'string'], 'valor' => ['type' => 'number'], 'unidad' => ['type' => 'string', 'enum' => ['mm', 'cm', 'm', 'in', 'ft']]], 'required' => ['clave', 'valor']]],
                    'cano_id' => ['type' => 'integer', 'description' => 'Para formas de caño: el id de la herramienta `canos`']], ['material', 'forma', 'medidas']),
            $f('convertir', 'Convierte unidades de longitud (mm, cm, m, in, ft) o de masa (g, kg, lb, ton). Acepta pulgadas fraccionarias ("1 1/2").',
                ['valor' => ['type' => 'string'], 'de' => ['type' => 'string'], 'a' => ['type' => 'string']], ['valor', 'de', 'a']),
            $f('calcular', 'Calcula una expresión numérica: + - * /, paréntesis y las funciones sqrt(x), pow(x; y), abs(x), min(x; y), max(x; y), floor(x), ceil(x), round(x; decimales) y pi. '
                .'Los argumentos se separan con «;». Usala para toda suma, resta, porcentaje, promedio, diferencia o rendimiento (cuántas piezas salen de una chapa: floor(1000 / 150) * floor(2000 / 150)): NO hagas cuentas de cabeza.',
                ['expresion' => ['type' => 'string']], ['expresion']),
            $f('reporte', 'Los reportes del sistema: material (por material), empresa (por empresa o por año), comprobantes (compras y facturas mes a mes), ranking, vencimientos. Cotizado y vendido por moneda.',
                ['tipo' => ['type' => 'string', 'enum' => array_keys(ReportesController::REPORTES)], 'periodo' => ['type' => 'string'], 'desde' => ['type' => 'string'], 'hasta' => ['type' => 'string'],
                    'empresa' => ['type' => 'string'], 'empresa_id' => ['type' => 'integer'], 'material' => ['type' => 'string'], 'por' => ['type' => 'string', 'enum' => ['empresas', 'materiales', 'usuarios']],
                    'medida' => ['type' => 'string', 'enum' => ['cotizaciones', 'vendidas', 'cotizado', 'vendido']], 'agrupar' => ['type' => 'string', 'enum' => ['empresa', 'anio']],
                    'top' => ['type' => 'integer']], ['tipo']),
        ];

        if ($this->usuario->veImportes()) {
            $herramientas[] = $f('evolucion_de_precios', 'Cómo se movió el precio en el tiempo: mínimo, promedio y máximo por año (o por mes), por moneda y unidad. Sirve para "cómo viene el precio de…", "subió o bajó", "comparado con el año pasado".',
                ['filtros' => $filtros, 'por' => ['type' => 'string', 'enum' => ['anio', 'mes'], 'description' => 'Por año (por defecto) o por mes']], ['filtros']);
            $herramientas[] = $f('precios', 'A cuánto se cotizó algo: mínimo, máximo, promedio, mediana y último precio por moneda y unidad, más las últimas cotizaciones. Sirve para "a cuánto le cotizamos / precio de".',
                ['filtros' => $filtros, 'ultimos' => ['type' => 'integer', 'description' => 'Cuántas cotizaciones recientes mostrar (máx. 20)']], ['filtros']);
        }

        return $herramientas;
    }

    /** Lo que se le muestra a la persona mientras la herramienta trabaja. */
    public function etiqueta(string $nombre, array $a): string
    {
        $de = fn (string ...$claves) => collect($claves)->map(fn ($c) => data_get($a, $c))->filter(fn ($v) => is_string($v) && $v !== '')->first();

        return match ($nombre) {
            'buscar' => 'Buscando '.($a['vista'] ?? 'en el sistema').'…',
            'agrupar' => 'Contando por '.($a['por'] ?? 'grupo').'…',
            'buscar_empresas' => 'Buscando la empresa «'.($a['texto'] ?? '').'»…',
            'empresa' => 'Abriendo la ficha'.($de('nombre') ? ' de '.$de('nombre') : '').'…',
            'cotizacion' => 'Leyendo la cotización'.($de('numero') ? ' '.$de('numero') : '').'…',
            'material' => 'Mirando el catálogo: '.($a['texto'] ?? 'material').'…',
            'formas' => 'Consultando las fórmulas de las formas…',
            'canos' => 'Buscando en la tabla de caños…',
            'calcular_peso' => 'Calculando el peso con la fórmula…',
            'convertir' => 'Convirtiendo unidades…',
            'calcular' => 'Haciendo la cuenta…',
            'reporte' => 'Armando el reporte '.($a['tipo'] ?? '').'…',
            'motor' => 'Consultando al sistema: «'.mb_substr((string) ($a['pregunta'] ?? ''), 0, 60).'»…',
            'evolucion_de_precios' => 'Viendo cómo se movió el precio'.($de('filtros.material', 'filtros.forma') ? ' de '.$de('filtros.material', 'filtros.forma') : '').'…',
            'precios' => 'Revisando a cuánto se cotizó'.($de('filtros.material', 'filtros.forma') ? ' '.$de('filtros.material', 'filtros.forma') : '').'…',
            default => 'Consultando…',
        };
    }

    /* ---------------------------------------------------------------- ejecutar */

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function ejecutar(string $nombre, array $a): array
    {
        try {
            $r = match ($nombre) {
                'buscar' => $this->buscar($a),
                'agrupar' => $this->agrupar($a),
                'buscar_empresas' => $this->buscarEmpresas($a),
                'empresa' => $this->empresa($a),
                'cotizacion' => $this->cotizacion($a),
                'material' => $this->material($a),
                'formas' => $this->formas($a),
                'canos' => $this->canos($a),
                'calcular_peso' => $this->calcularPeso($a),
                'convertir' => $this->convertir($a),
                'calcular' => $this->calcular($a),
                'reporte' => $this->reporte($a),
                'precios' => $this->precios($a),
                'evolucion_de_precios' => $this->evolucionDePrecios($a),
                'motor' => $this->motor($a),
                default => ['error' => "No existe la herramienta «{$nombre}»."],
            };
        } catch (HttpResponseException|ValidationException $e) {
            $r = ['error' => 'El pedido no es válido: '.collect($e instanceof ValidationException ? $e->errors() : [])->flatten()->implode(' ')];
        } catch (HttpException $e) {
            $r = ['error' => $e->getMessage() ?: 'No se pudo hacer.'];
        } catch (\Throwable $e) {
            report($e);
            $r = ['error' => 'No pude hacer esa consulta ('.class_basename($e).'). Probá de otra manera.'];
        }

        return $this->acotar($r);
    }

    /* ------------------------------------------------------------ búsquedas */

    /**
     * @param  array<string, mixed>  $a
     * @return array{0: array<string, string>, 1: list<string>, 2: Filtros}
     */
    private function filtrosDe(array $a, ?string $vista, int $porPagina = self::TOPE_FILAS): array
    {
        $filtros = is_array($a['filtros'] ?? null) ? $a['filtros'] : [];
        $borrador = $filtros + array_filter(['vista' => $vista], fn ($v) => $v !== null);
        $r = $this->resolver->resolver($borrador);
        $pedido = $r['pedido'];

        return [$pedido, $r['no_entendi'], Filtros::deArray($pedido + ['por_pagina' => (string) max(1, min($porPagina, self::TOPE_FILAS)), 'pagina' => '1'])];
    }

    /** @return array<string, mixed> */
    private function buscar(array $a): array
    {
        [$pedido, $noEntendi, $f] = $this->filtrosDe($a, $a['vista'] ?? null, (int) ($a['limite'] ?? self::TOPE_FILAS));
        $r = $this->buscador->pagina($f);
        $filas = array_map(fn ($x) => $this->fila($f->grano, $x), $r['data']);

        return [
            'vista' => $f->grano,
            'total' => $r['meta']['total'],
            'mostrando' => count($filas),
            'filas' => $filas,
            'resumen' => $r['resumen'],
            'filtros_aplicados' => $pedido,
            'no_entendi' => $noEntendi,
            'tablas' => $filas === [] ? [] : [$this->tablaDeFilas($f->grano, $filas, $r['meta']['total'])],
        ];
    }

    /** @return array<string, mixed> */
    private function agrupar(array $a): array
    {
        [$pedido, $noEntendi, $f] = $this->filtrosDe($a, $a['vista'] ?? null);
        $por = (string) ($a['por'] ?? '');

        try {
            $grupos = $this->buscador->agrupar($f, $por, (int) ($a['limite'] ?? 10));
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        $campo = ['provincia' => 'provincia_id', 'localidad' => 'localidad_id', 'rubro' => 'rubro_id', 'empresa' => 'empresa_id', 'material' => 'material_id',
            'forma' => 'forma_id', 'usuario' => 'usuario_id', 'moneda' => 'moneda_id', 'estado' => 'estado',
            'tipo' => $f->grano === 'comprobantes' ? 'tipo_comprobante' : 'tipo'][$por] ?? null;
        $total = $this->buscador->contar($f);

        $grupos = array_map(function ($g) use ($campo, $por) {
            $filtro = match (true) {
                $campo === null && $por === 'mes' && preg_match('/^\d{4}-\d{2}$/', $g['clave']) === 1 => ['desde' => $g['clave'].'-01', 'hasta' => date('Y-m-t', strtotime($g['clave'].'-01'))],
                $campo === null && $por === 'anio' && preg_match('/^\d{4}$/', $g['clave']) === 1 => ['desde' => $g['clave'].'-01-01', 'hasta' => $g['clave'].'-12-31'],
                $campo === null || $g['clave'] === '' => null,
                in_array($campo, ['estado', 'tipo', 'tipo_comprobante'], true) => [$campo => $g['etiqueta']],
                default => [$campo => (int) $g['clave']],
            };

            return ['etiqueta' => $g['etiqueta'], 'cantidad' => $g['n'], 'filtro' => $filtro];
        }, $grupos);

        return [
            'vista' => $f->grano, 'por' => $por, 'total' => $total, 'grupos' => $grupos,
            'filtros_aplicados' => $pedido, 'no_entendi' => $noEntendi,
            'tablas' => [[
                'titulo' => 'Por '.$por,
                'columnas' => [ucfirst($por), 'Cantidad'],
                'filas' => array_map(fn ($g) => [$g['etiqueta'], $g['cantidad']], $grupos),
                'acciones' => array_map(fn ($g) => $g['filtro'] ? [['tipo' => 'pedido', 'pedido' => $g['filtro'] + $pedido]] : [], $grupos),
            ]],
        ];
    }

    /**
     * Las empresas que se llaman así (nombre, razón social o CUIT), sin recorrer renglones ni contactos: con muchos datos la
     * búsqueda del Buscador —que mira hasta la descripción de cada renglón— sería lenta para algo tan simple como "¿cuál es ALFA?".
     *
     * @return Collection<int, Empresa>
     */
    public function empresasPorNombre(string $texto, int $limite = 8)
    {
        $norm = Texto::normalizar($texto);
        $palabras = array_values(array_filter(explode(' ', $norm), fn ($p) => mb_strlen($p) >= 2));
        $digitos = preg_replace('/\D+/', '', $texto) ?? '';

        if ($palabras === [] && strlen($digitos) < 8) {
            return collect();
        }

        $q = Empresa::query()
            ->with(['localidad', 'provincia', 'rubro', 'relaciones', 'contactos'])
            ->withCount(['consultas as cotizaciones_count' => fn ($c) => $c->where('tipo', 'Cotizacion')])
            ->addSelect(['ultima_cotizacion' => Consulta::query()->select('fecha')->whereColumn('consultas.empresa_id', 'empresas.id')->where('tipo', 'Cotizacion')->orderByDesc('fecha')->limit(1)]);

        $q->where(function ($w) use ($palabras, $digitos) {
            if (strlen($digitos) >= 8) {
                $w->orWhereRaw("replace(empresas.cuit, '-', '') like ?", ["%{$digitos}%"]);
            }

            if ($palabras !== []) {
                $w->orWhere(function ($todas) use ($palabras) {
                    foreach ($palabras as $p) {
                        $todas->where(fn ($x) => $x->where('empresas.nombre', 'like', "%{$p}%")
                            ->orWhereHas('razonesSociales', fn ($r) => $r->where('razon_social', 'like', "%{$p}%")));
                    }
                });
            }
        });

        // Primero la que se llama igual, después las que empiezan así, las activas y las que más se cotizaron.
        return $q->limit(80)->get()->sortBy(fn (Empresa $e) => [
            Texto::normalizar($e->nombre) === $norm ? 0 : (str_starts_with(Texto::normalizar($e->nombre), $norm) ? 1 : 2),
            $e->activa ? 0 : 1,
            -1 * (int) $e->cotizaciones_count,
            mb_strlen($e->nombre),
        ])->take(max(1, $limite))->values();
    }

    /** @return array<string, mixed> la fila de una empresa, con los mismos nombres que las del Buscador */
    private function filaDeEmpresaLiviana(Empresa $e): array
    {
        return [
            'id' => $e->id, 'nombre' => $e->nombre, 'cuit' => $e->cuit, 'relaciones' => $e->relaciones->where('activa', true)->pluck('relacion')->values()->all(),
            'localidad' => $e->localidad?->nombre, 'provincia' => $e->provincia?->nombre, 'rubro' => $e->rubro?->nombre, 'activa' => (bool) $e->activa,
            'cotizaciones' => (int) $e->cotizaciones_count, 'ultima_cotizacion' => $e->ultima_cotizacion ? substr((string) $e->ultima_cotizacion, 0, 10) : null,
            'contacto' => $e->contactos->firstWhere('principal', true)?->nombre ?? $e->contactos->first()?->nombre,
        ];
    }

    /** @return array<string, mixed> */
    private function buscarEmpresas(array $a): array
    {
        $texto = trim((string) ($a['texto'] ?? ''));

        if (mb_strlen($texto) < 2) {
            return ['error' => 'Escribí al menos dos letras del nombre.'];
        }

        $empresas = $this->empresasPorNombre($texto, max(1, min((int) ($a['limite'] ?? 8), self::TOPE_FILAS)));
        $filas = $empresas->map(fn (Empresa $e) => $this->filaDeEmpresaLiviana($e))->all();

        return [
            'total' => count($filas), 'empresas' => $filas,
            'nota' => count($filas) >= 8 ? 'Puede haber más: pedí un nombre más preciso.' : null,
            'tablas' => $filas === [] ? [] : [$this->tablaDeFilas('empresas', $filas, count($filas))],
        ];
    }

    /** @return array<string, mixed> */
    private function precios(array $a): array
    {
        if (! $this->usuario->veImportes()) {
            return ['error' => 'Esta persona no tiene permiso para ver precios.'];
        }

        [$pedido, $noEntendi, $f] = $this->filtrosDe($a, 'renglones');
        $r = $this->buscador->precios($f, (int) ($a['ultimos'] ?? 8));

        $tablas = [];

        if ($r['estadisticas'] !== []) {
            $tablas[] = [
                'titulo' => 'A cuánto se cotizó',
                'columnas' => ['Moneda', 'Unidad', 'Renglones', 'Mínimo', 'Promedio', 'Mediana', 'Máximo', 'Último precio', 'Fecha'],
                'filas' => array_map(fn ($e) => [$e['moneda'], $e['unidad'], $e['renglones'], $this->plata($e['minimo']), $this->plata($e['promedio']),
                    $e['mediana'] === null ? '—' : $this->plata($e['mediana']), $this->plata($e['maximo']),
                    $e['ultimo_precio'] === null ? '—' : $this->plata($e['ultimo_precio']), $e['ultima_fecha'] ? $this->fecha($e['ultima_fecha']) : '—'], $r['estadisticas']),
            ];
        }

        if ($r['ultimos'] !== []) {
            $tablas[] = [
                'titulo' => 'Últimas cotizaciones',
                'columnas' => ['Fecha', 'Empresa', 'Cotización', 'Descripción', 'Cant.', 'Unidad', 'Precio', 'Moneda'],
                'filas' => array_map(fn ($u) => [$this->fecha($u['fecha']), $u['empresa'], $u['numero'] ?? '—', $u['descripcion'], $u['cantidad'], $u['unidad'] ?? '—', $this->plata($u['precio']), $u['moneda'] ?? '—'], $r['ultimos']),
                'acciones' => array_map(fn ($u) => [['tipo' => 'cotizacion', 'id' => $u['cotizacion_id']]], $r['ultimos']),
            ];
        }

        return $r + [
            'filtros_aplicados' => $pedido, 'no_entendi' => $noEntendi, 'tablas' => $tablas,
            'nota' => $r['estadisticas'] === [] ? 'No hay renglones con precio para ese pedido (revisá el material o el período).' : 'Cada fila de estadística es una moneda y una unidad: no se mezclan. Mínimo, máximo y promedio son de TODOS los renglones; si dice mediana_de_los_ultimos, la mediana es sólo de esos más recientes.',
        ];
    }

    /** @return array<string, mixed> */
    private function evolucionDePrecios(array $a): array
    {
        if (! $this->usuario->veImportes()) {
            return ['error' => 'Esta persona no tiene permiso para ver precios.'];
        }

        [$pedido, $noEntendi, $f] = $this->filtrosDe($a, 'renglones');
        $por = ($a['por'] ?? 'anio') === 'mes' ? 'mes' : 'anio';
        $periodos = $this->buscador->evolucionDePrecios($f, $por);

        return [
            'por' => $por, 'periodos' => $periodos, 'filtros_aplicados' => $pedido, 'no_entendi' => $noEntendi,
            'tablas' => $periodos === [] ? [] : [[
                'titulo' => 'Cómo se movió el precio, por '.($por === 'mes' ? 'mes' : 'año'),
                'columnas' => ['Período', 'Moneda', 'Unidad', 'Renglones', 'Mínimo', 'Promedio', 'Máximo'],
                'filas' => array_map(fn ($p) => [$p['periodo'], $p['moneda'], $p['unidad'], $p['renglones'], $this->plata($p['minimo']), $this->plata($p['promedio']), $this->plata($p['maximo'])], $periodos),
            ]],
            'nota' => $periodos === [] ? 'No hay renglones con precio para ese pedido (revisá el material o el período).' : 'Cada fila es un período, una moneda y una unidad: no se mezclan. Los renglones viejos importados pueden no tener el material enlazado.',
        ];
    }

    /**
     * Le pasa una sub-pregunta al motor del sistema (Entiende + Redactor: el mismo que contesta sin IA). Es lo que le permite a la IA
     * orquestar: partir una pregunta compleja en pasos simples y que cada uno lo resuelva el código, con exactitud.
     *
     * @return array<string, mixed>
     */
    private function motor(array $a): array
    {
        $texto = trim(preg_replace('/\s+/', ' ', (string) ($a['pregunta'] ?? '')) ?? '');

        if (mb_strlen($texto) < 3) {
            return ['error' => 'Escribí la sub-pregunta completa.'];
        }

        $plan = (new Entiende($this->catalogo))->plan(mb_substr($texto, 0, 300));

        if ($plan === null) {
            // Una comparación, una suma de piezas, dos preguntas en una: el descompositor de código la resuelve entera y con las cuentas bien
            // (no mezcla dólares con pesos ni unidades distintas). Es lo que la IA tiene que usar para comparar, en vez de armarlo ella.
            $d = (new Descompone($this->usuario, $this->catalogo))->resolver(mb_substr($texto, 0, 300));

            if ($d !== null) {
                return array_filter([
                    'entendio' => true,
                    'intencion' => 'varias partes',
                    'respuesta' => $d['mensaje'],
                    'tablas' => $d['tablas'],
                    'faltan' => $d['opciones'] !== null ? ['pregunta' => $d['opciones']['pregunta'], 'opciones' => array_column($d['opciones']['opciones'], 'etiqueta')] : null,
                    'nota' => 'Es el resultado exacto del sistema, con las cuentas ya hechas: usalo tal cual, y no recalcules ni compares de cabeza.',
                ], fn ($v) => $v !== null && $v !== []);
            }

            return ['entendio' => false, 'nota' => 'El motor no reconoció esa pregunta: usá las herramientas finas (material, formas, canos, calcular_peso, precios, empresa…).'];
        }

        $r = (new Redactor($this->usuario, $this->catalogo))->redactar($plan);

        if ($r === null) {
            return ['entendio' => false, 'intencion' => $plan['intencion'], 'nota' => 'El motor entendió el tipo de pregunta pero no pudo contestarla: usá las herramientas finas.'];
        }

        unset($r['necesita_medidas'], $r['_calculo']);

        return array_filter([
            'entendio' => true,
            'intencion' => $plan['intencion'],
            'respuesta' => $r['mensaje'],
            'aviso' => $r['aviso'],
            'tablas' => $r['tablas'],
            'faltan' => $r['opciones'] !== null ? ['pregunta' => $r['opciones']['pregunta'], 'opciones' => array_column($r['opciones']['opciones'], 'etiqueta')] : null,
            'nota' => 'Es el resultado exacto del sistema: usalo tal cual, y no recalcules de cabeza.',
        ], fn ($v) => $v !== null && $v !== []);
    }

    /* ------------------------------------------------------------- empresas */

    /** @return array<string, mixed> */
    private function empresa(array $a): array
    {
        $e = null;

        if (! empty($a['id'])) {
            $e = Empresa::query()->find((int) $a['id']);
        } elseif (filled($a['nombre'] ?? null)) {
            $n = trim((string) $a['nombre']);
            $c = $this->empresasPorNombre($n, 6);
            $exacta = $c->first(fn ($x) => Texto::normalizar($x->nombre) === Texto::normalizar($n));

            if ($c->count() > 1 && ! $exacta) {
                return [
                    'ambigua' => true,
                    'nota' => 'Hay varias empresas con ese nombre: preguntale a la persona cuál, con opciones.',
                    'candidatas' => $c->map(fn ($x) => ['id' => $x->id, 'nombre' => $x->nombre, 'localidad' => $x->localidad?->nombre, 'provincia' => $x->provincia?->nombre, 'activa' => (bool) $x->activa])->all(),
                ];
            }

            $e = $exacta ?? $c->first();
        }

        if (! $e) {
            return ['error' => 'No encontré esa empresa. Probá con `buscar_empresas`.'];
        }

        $e->load(['contactos.medios.tipoMedio', 'campos', 'razonesSociales', 'relaciones', 'localidad', 'provincia', 'rubro']);
        $ve = $this->usuario->veImportes();

        $porEstado = DB::table('consultas')->where('empresa_id', $e->id)->whereNull('deleted_at')->selectRaw('tipo, estado, count(*) n')->groupBy('tipo', 'estado')->get();
        $fechas = DB::table('consultas')->where('empresa_id', $e->id)->whereNull('deleted_at')->where('tipo', 'Cotizacion')->where('estado', '!=', 'Borrador')->selectRaw('min(fecha) primera, max(fecha) ultima, count(*) n')->first();
        $materiales = DB::table('consulta_lineas as l')->join('consultas as c', 'c.id', '=', 'l.consulta_id')->leftJoin('materiales as m', 'm.id', '=', 'l.material_id')
            ->where('c.empresa_id', $e->id)->whereNull('c.deleted_at')->whereNotNull('m.nombre')->where('l.quitada', false)
            ->selectRaw('m.nombre, count(*) n')->groupBy('m.nombre')->orderByDesc('n')->limit(6)->get();

        $r = [
            'id' => $e->id, 'nombre' => $e->nombre, 'cuit' => $e->cuit, 'activa' => (bool) $e->activa,
            'ubicacion' => collect([$e->direccion, $e->localidad?->nombre, $e->provincia?->nombre])->filter()->implode(', ') ?: null,
            'rubro' => $e->rubro?->nombre, 'relaciones' => $e->relaciones->where('activa', true)->pluck('relacion')->values()->all(),
            'condicion_iva' => $e->condicion_iva, 'condicion_pago' => $e->condicion_pago, 'observacion' => $e->observacion_general ? mb_substr($e->observacion_general, 0, 300) : null,
            'razones_sociales' => $e->razonesSociales->map(fn ($r) => trim($r->razon_social.' '.($r->cuit ? '(CUIT '.$r->cuit.')' : '')))->values()->all(),
            'contactos' => $e->contactos->where('activo', true)->take(8)->map(fn ($c) => [
                'nombre' => $c->nombre, 'cargo' => $c->cargo, 'sector' => $c->sector, 'principal' => (bool) $c->principal,
                'medios' => $c->medios->where('activo', true)->map(fn ($m) => ($m->tipoMedio?->nombre ?? 'Dato').': '.$m->valor)->values()->all(),
            ])->values()->all(),
            'condiciones_de_la_ficha' => $e->campos->map(fn ($c) => $c->titulo.': '.$c->valor)->values()->all(),
            'cotizaciones_firmes' => (int) ($fechas->n ?? 0), 'primera_cotizacion' => $fechas?->primera ? substr((string) $fechas->primera, 0, 10) : null,
            'ultima_cotizacion' => $fechas?->ultima ? substr((string) $fechas->ultima, 0, 10) : null,
            'por_estado' => $porEstado->map(fn ($x) => "{$x->tipo} · {$x->estado}: {$x->n}")->values()->all(),
            'materiales_mas_cotizados' => $materiales->map(fn ($m) => "{$m->nombre}: {$m->n} renglones")->values()->all(),
        ];

        if ($ve) {
            $r['cotizado_por_moneda'] = DB::table('consulta_lineas as l')->join('consultas as c', 'c.id', '=', 'l.consulta_id')->leftJoin('monedas as mo', 'mo.id', '=', 'c.moneda_id')
                ->where('c.empresa_id', $e->id)->whereNull('c.deleted_at')->where('c.tipo', 'Cotizacion')->where('c.estado', '!=', 'Borrador')
                ->where('l.quitada', false)->where('l.no_cotizada', false)->whereNull('l.alternativa_de_id')
                ->selectRaw('mo.nombre moneda, sum(l.importe) importe')->groupBy('mo.nombre')->get()
                ->map(fn ($x) => ['moneda' => $x->moneda ?? 'Sin moneda', 'importe' => round((float) $x->importe, 2)])->values()->all();
        }

        return $r;
    }

    /** @return array<string, mixed> */
    private function cotizacion(array $a): array
    {
        $c = null;

        if (! empty($a['id'])) {
            $c = Consulta::query()->find((int) $a['id']);
        } elseif (filled($a['numero'] ?? null)) {
            $n = trim((string) $a['numero']);
            $n = preg_replace('/\s*R\d+$/i', '', $n) ?? $n;
            $c = Consulta::query()->where(fn ($q) => $q->where('numero', $n)->orWhere('id_sistema', $n)->orWhere('solicitud_nro', $n)->orWhere('nro_pedido_interno', $n))
                ->orderByDesc('revision')->first();
        }

        if (! $c) {
            return ['error' => 'No encontré esa cotización. Revisá el número.'];
        }

        $c->load(['empresa', 'moneda', 'usuario', 'comprobantes', 'lineas' => fn ($q) => $q->where('quitada', false)->orderBy('orden')->limit(40), 'lineas.unidadVenta', 'lineas.material', 'lineas.forma']);
        $ve = $this->usuario->veImportes();

        $lineas = $c->lineas->map(fn ($l) => array_filter([
            'descripcion' => mb_substr((string) $l->descripcion, 0, 160), 'material' => $l->material?->nombre, 'forma' => $l->forma?->nombre,
            'cantidad' => $l->cantidad !== null ? (float) $l->cantidad : null, 'unidad' => $l->unidadVenta?->codigo,
            'alternativa' => $l->alternativa_de_id !== null ? true : null, 'no_cotizada' => $l->no_cotizada ? true : null,
            'precio_unitario' => $ve && $l->precio_unitario !== null ? round((float) $l->precio_unitario, 2) : null,
            'importe' => $ve && $l->importe !== null ? round((float) $l->importe, 2) : null,
        ], fn ($v) => $v !== null))->values()->all();

        return [
            'id' => $c->id, 'numero' => $c->numeroConRevision(), 'tipo' => $c->tipo, 'estado' => $c->estado, 'fecha' => $c->fecha?->format('Y-m-d'),
            'vence_el' => $c->vence_el?->format('Y-m-d'), 'empresa' => ['id' => $c->empresa_id, 'nombre' => $c->empresa?->nombre],
            'moneda' => $c->moneda?->nombre, 'hizo' => $c->usuario?->iniciales, 'pedido_del_cliente' => $c->solicitud_nro,
            'condicion_de_pago' => $c->condicion_pago, 'comprobantes' => $c->comprobantes->map(fn ($k) => trim($k->tipo.' '.$k->numero.($k->fecha ? ' ('.$k->fecha->format('Y-m-d').')' : '')))->values()->all(),
            'total' => $ve ? round((float) $c->total, 2) : null, 'renglones' => $lineas,
        ];
    }

    /* ---------------------------------------------------------------- catálogo */

    /** @return array<string, mixed> */
    private function material(array $a): array
    {
        $t = trim((string) ($a['texto'] ?? ''));

        if ($t === '') {
            return ['error' => 'Decime qué material.'];
        }

        $n = Texto::normalizar($t);
        $encontrados = Material::query()->where('activo', true)->where(fn ($q) => $q->where('nombre', 'like', "%{$t}%")->orWhere('uns', 'like', "%{$t}%")
            ->orWhere('w_nr', 'like', "%{$t}%")->orWhereHas('alias', fn ($x) => $x->where('alias', 'like', "%{$t}%")))->with('alias', 'formaHabitual')->limit(40)->get()
            ->sortBy(fn ($m) => [Texto::normalizar($m->nombre) === $n ? 0 : (str_starts_with(Texto::normalizar($m->nombre), $n) ? 1 : 2), mb_strlen($m->nombre)])->take(8)->values();

        if ($encontrados->isEmpty()) {
            return ['error' => "No hay un material «{$t}» en el catálogo.", 'parecidos' => $this->materialesParecidos($n)];
        }

        $veces = DB::table('consulta_lineas')->whereIn('material_id', $encontrados->pluck('id'))->selectRaw('material_id, count(*) n')->groupBy('material_id')->pluck('n', 'material_id');

        return [
            'total_coincidencias' => $encontrados->count(),
            'materiales' => $encontrados->map(fn (Material $m) => [
                'id' => $m->id, 'nombre' => $m->nombre, 'familia' => $m->familia, 'densidad_g_cm3' => $m->densidad !== null && (float) $m->densidad > 0 ? (float) $m->densidad : null,
                'uns' => $m->uns, 'w_nr' => $m->w_nr, 'alias' => $m->alias->pluck('alias')->take(8)->values()->all(),
                'forma_habitual' => $m->formaHabitual?->nombre, 'renglones_cotizados' => (int) ($veces[$m->id] ?? 0),
            ])->all(),
            'nota' => $encontrados->contains(fn ($m) => $m->densidad === null || (float) $m->densidad <= 0) ? 'Los que tienen densidad null no permiten calcular peso.' : null,
        ];
    }

    /** @return list<string> */
    private function materialesParecidos(string $normalizado): array
    {
        $candidatos = [];
        $limite = max(1, (int) floor(mb_strlen($normalizado) / 4));

        foreach ($this->catalogo->materiales() as $clave => $canonico) {
            // Se compara con el nombre entero y con cada palabra: «titanyo» se parece a «titanio» de «Titanio Grado 2».
            foreach ([$clave, ...array_filter(explode(' ', $clave), fn ($w) => mb_strlen($w) >= 4)] as $parte) {
                $d = levenshtein(mb_substr($normalizado, 0, 60), mb_substr($parte, 0, 60));

                if ($d <= $limite) {
                    $candidatos[$canonico] = min($candidatos[$canonico] ?? 99, $d);
                }
            }
        }

        asort($candidatos);

        return array_slice(array_keys($candidatos), 0, 5);
    }

    /** @return array<string, mixed> */
    private function formas(array $a): array
    {
        $t = Texto::normalizar((string) ($a['texto'] ?? ''));
        $formas = Forma::query()->where('activo', true)->orderBy('orden')->orderBy('nombre')->get()
            ->filter(fn (Forma $f) => $t === '' || str_contains(Texto::normalizar($f->nombre), $t) || str_contains($t, Texto::normalizar($f->nombre)));

        if ($formas->isEmpty()) {
            return ['error' => 'No encontré esa forma.', 'formas_disponibles' => Forma::query()->where('activo', true)->pluck('nombre')->all()];
        }

        return ['formas' => $formas->take(25)->map(fn (Forma $f) => [
            'id' => $f->id, 'nombre' => $f->nombre, 'usa_cano' => (bool) $f->usa_cano,
            'medidas' => collect($f->camposDelCalculo())->map(fn ($c) => ['clave' => $c['clave'], 'nombre' => $c['label'], 'tipo' => $c['tipo']])->values()->all(),
            'formula_del_volumen_cm3' => blank($f->expresion) ? null : $f->expresion,
            'calcula_peso' => $f->tieneCalculoAutomatico(),
        ])->values()->all()];
    }

    /** @return array<string, mixed> */
    private function canos(array $a): array
    {
        $q = CanoEstandar::query()->where('activo', true);
        $nominal = null;

        if (filled($a['dn'] ?? null) && preg_match('/\d+/', (string) $a['dn'], $dn)) {
            $texto = Medidas::cano(Medidas::limpiar('dn'.$dn[0]));
            $nominal = $texto['nominal'];
        } elseif (filled($a['nominal'] ?? null)) {
            $n = Medidas::numero(trim(str_replace(['"', '”', '″', 'pulgadas', 'pulgada', 'pulg', 'in'], '', (string) $a['nominal'])));
            $nominal = $n !== null ? Medidas::nombreDeNominal($n) : null;
        }

        if ($nominal !== null) {
            $q->where('nombre', $nominal);
        }

        $schedule = filled($a['schedule'] ?? null) ? strtoupper(trim((string) $a['schedule'])) : null;

        if ($schedule !== null) {
            $q->where('schedule', $schedule);
        }

        $material = filled($a['material'] ?? null) ? $this->materialPorNombre((string) $a['material']) : null;
        $densidad = $material && (float) $material->densidad > 0 ? (float) $material->densidad : null;
        $filas = $q->orderBy('diametro_mm')->orderBy('pared_mm')->limit(40)->get()->map(function (CanoEstandar $c) use ($densidad) {
            $od = (float) $c->diametro_mm;
            $pared = (float) $c->pared_mm;
            $interior = $od - 2 * $pared;
            $seccion = M_PI / 4 * ($od ** 2 - $interior ** 2);

            return array_filter([
                'id' => $c->id, 'nominal' => $c->nombre, 'schedule' => $c->schedule, 'diametro_exterior_mm' => $od, 'pared_mm' => $pared,
                'diametro_interior_mm' => round($interior, 2), 'seccion_mm2' => round($seccion, 2),
                'kg_por_metro' => $densidad ? round($seccion * $densidad / 1000, 3) : null,
            ], fn ($v) => $v !== null);
        })->values()->all();

        if ($filas === []) {
            $schedules = $nominal !== null ? CanoEstandar::query()->where('activo', true)->where('nombre', $nominal)->orderBy('pared_mm')->pluck('schedule')->unique()->values()->all() : [];

            return ['error' => 'No hay un caño con ese diámetro y schedule en la tabla.', 'schedules_de_ese_diametro' => $schedules];
        }

        return [
            'material' => $material?->nombre, 'densidad_g_cm3' => $densidad, 'cantidad' => count($filas), 'canos' => $filas,
            'nota' => $material && ! $densidad ? 'Ese material no tiene densidad cargada: no se pueden calcular los kg por metro.' : null,
            'tablas' => [[
                'titulo' => 'Caños de la tabla'.($nominal ? " · {$nominal}" : '').($schedule ? " · SCH {$schedule}" : ''),
                'columnas' => array_values(array_filter(['Nominal', 'Schedule', 'Ø exterior (mm)', 'Pared (mm)', 'Ø interior (mm)', $densidad ? 'kg por metro' : null])),
                'filas' => array_map(fn ($c) => array_values(array_filter([$c['nominal'], $c['schedule'], $c['diametro_exterior_mm'], $c['pared_mm'], $c['diametro_interior_mm'], $densidad ? $c['kg_por_metro'] : null], fn ($v) => $v !== null)), $filas),
            ]],
        ];
    }

    private function materialPorNombre(string $texto): ?Material
    {
        $canonico = $this->catalogo->materiales()[Texto::normalizar($texto)] ?? $this->catalogo->materialEnTexto($texto);

        return $canonico
            ? Material::query()->where('activo', true)->where('nombre', $canonico)->first()
            : Material::query()->where('activo', true)->where('nombre', 'like', '%'.trim($texto).'%')->orderByRaw('length(nombre)')->first();
    }

    /** @return array<string, mixed> */
    private function calcularPeso(array $a): array
    {
        $material = is_numeric($a['material'] ?? null) ? Material::query()->find((int) $a['material']) : $this->materialPorNombre((string) ($a['material'] ?? ''));
        $formaId = is_numeric($a['forma'] ?? null) ? (int) $a['forma'] : $this->catalogo->forma((string) ($a['forma'] ?? ''));
        $forma = $formaId ? Forma::query()->find($formaId) : null;

        if (! $material) {
            return ['error' => 'No encontré ese material en el catálogo.', 'parecidos' => $this->materialesParecidos(Texto::normalizar((string) ($a['material'] ?? '')))];
        }

        if (! $forma) {
            return ['error' => 'No encontré esa forma.', 'formas_disponibles' => Forma::query()->where('activo', true)->pluck('nombre')->all()];
        }

        $medidas = [];

        foreach ((array) ($a['medidas'] ?? []) as $m) {
            if (is_array($m) && isset($m['clave'], $m['valor'])) {
                $medidas[(string) $m['clave']] = ['valor' => (float) $m['valor'], 'unidad' => in_array($m['unidad'] ?? 'mm', ['mm', 'cm', 'm', 'in', 'ft'], true) ? ($m['unidad'] ?? 'mm') : 'mm'];
            }
        }

        $cano = ! empty($a['cano_id']) ? CanoEstandar::query()->find((int) $a['cano_id']) : null;
        $r = app(CalculadoraDePeso::class)->calcular($material, $forma, $medidas, max(0.0001, (float) ($a['piezas'] ?? 1)), 'kg', $cano);

        $salida = [
            'ok' => (bool) $r['ok'], 'material' => $material->nombre, 'forma' => $forma->nombre, 'densidad_g_cm3' => $r['densidad_g_cm3'],
            'formula_del_volumen_cm3' => $r['formula'], 'piezas' => $r['piezas'], 'medidas_en_mm' => $r['medidas'],
        ];

        if ($r['ok']) {
            return $salida + ['volumen_por_pieza_cm3' => $r['volumen_por_pieza_cm3'], 'peso_por_pieza_kg' => $r['peso_por_pieza_kg'], 'peso_total_kg' => $r['peso_total_kg']];
        }

        $salida['motivo'] = $r['motivo'];

        if (! $material->densidad || (float) $material->densidad <= 0) {
            $salida['materiales_parecidos_con_densidad'] = Material::query()->where('activo', true)->where('densidad', '>', 0)
                ->when($material->familia, fn ($q, $f) => $q->where('familia', $f))->orderBy('nombre')->limit(8)->get(['nombre', 'densidad'])
                ->map(fn ($m) => $m->nombre.' ('.(float) $m->densidad.' g/cm³)')->all();
        }

        return $salida;
    }

    /* ---------------------------------------------------------------- cuentas */

    /** @return array<string, mixed> */
    private function convertir(array $a): array
    {
        $largos = ['mm' => 1.0, 'cm' => 10.0, 'm' => 1000.0, 'mt' => 1000.0, 'in' => 25.4, 'pulgada' => 25.4, 'pulgadas' => 25.4, '"' => 25.4, 'ft' => 304.8, 'pie' => 304.8, 'pies' => 304.8];
        $masas = ['g' => 1.0, 'kg' => 1000.0, 'lb' => 453.59237, 'libra' => 453.59237, 'libras' => 453.59237, 'ton' => 1_000_000.0, 'tn' => 1_000_000.0];
        $de = strtolower(trim((string) ($a['de'] ?? '')));
        $hacia = strtolower(trim((string) ($a['a'] ?? '')));
        $valor = $this->numeroFraccionario((string) ($a['valor'] ?? ''));

        if ($valor === null) {
            return ['error' => 'No entendí el número. Ej: 38,1 o 1 1/2.'];
        }

        foreach ([$largos, $masas] as $tabla) {
            if (isset($tabla[$de], $tabla[$hacia])) {
                $r = $valor * $tabla[$de] / $tabla[$hacia];

                return ['valor' => $valor, 'de' => $de, 'a' => $hacia, 'resultado' => round($r, 6), 'cuenta' => "{$valor} {$de} × {$tabla[$de]} ÷ {$tabla[$hacia]}"];
            }
        }

        return ['error' => 'Sólo convierto longitudes (mm, cm, m, in, ft) o masas (g, kg, lb, ton), y las dos unidades tienen que ser del mismo tipo.'];
    }

    private function numeroFraccionario(string $t): ?float
    {
        $t = trim(str_replace(',', '.', $t));

        if (preg_match('/^(\d+(?:\.\d+)?)$/', $t, $m)) {
            return (float) $m[1];
        }

        if (preg_match('/^(?:(\d+)\s+)?(\d+)\s*\/\s*(\d+)$/', $t, $m) && (int) $m[3] !== 0) {
            return (float) ($m[1] !== '' ? $m[1] : 0) + (int) $m[2] / (int) $m[3];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function calcular(array $a): array
    {
        $e = trim((string) ($a['expresion'] ?? ''));
        // «1,5» es un decimal; los argumentos de una función se separan con «;» (o con coma y espacio).
        $e = preg_replace('/(?<=\d),(?=\d)/', '.', $e) ?? $e;
        $e = str_replace([';', '×', '÷', '^'], [',', '*', '/', '**'], $e);

        if ($e === '' || mb_strlen($e) > 200) {
            return ['error' => 'Escribí la cuenta, por ejemplo: 1059.84 + 365.52'];
        }

        // El evaluador toma cualquier palabra como una medida en cero: acá sólo se aceptan números, operadores y estas funciones.
        if (preg_match('/[a-z]/i', preg_replace('/\b(?:pow|pi|sqrt|abs|min|max|floor|ceil|round)\b/i', '', $e) ?? '')) {
            return ['error' => 'Sólo puedo calcular con números, + - * /, paréntesis y las funciones sqrt, pow, abs, min, max, floor, ceil, round y pi.'];
        }

        // Potencias con ^ o **: el evaluador entiende pow().
        $e = preg_replace('/(\d+(?:\.\d+)?)\s*\*\*\s*(\d+(?:\.\d+)?)/', 'pow($1, $2)', $e) ?? $e;

        try {
            $r = app(EvaluadorDeFormulas::class)->conRedondeos()->evaluar(strtolower($e), []);
        } catch (\Throwable) {
            return ['error' => 'No pude resolver esa cuenta. Usá números, + - * /, paréntesis y funciones como sqrt(), pow(x; y), floor(), ceil(), round(x; decimales).'];
        }

        if (! is_finite($r)) {
            return ['error' => 'Esa cuenta no tiene resultado (¿dividís por cero?).'];
        }

        return ['expresion' => $e, 'resultado' => round($r, 6)];
    }

    /* ---------------------------------------------------------------- reportes */

    /** @return array<string, mixed> */
    private function reporte(array $a): array
    {
        $tipo = (string) ($a['tipo'] ?? '');

        if (! isset(ReportesController::REPORTES[$tipo])) {
            return ['error' => 'Reportes: '.implode(', ', array_keys(ReportesController::REPORTES)).'.'];
        }

        $desde = $a['desde'] ?? null;
        $hasta = $a['hasta'] ?? null;

        if (! $desde && ! $hasta && is_string($a['periodo'] ?? null) && ($p = Periodo::de($a['periodo'], $this->catalogo->hoy()))) {
            [$desde, $hasta] = [$p['desde'], $p['hasta']];
        }

        $parametros = array_filter([
            'desde' => $desde, 'hasta' => $hasta, 'empresa' => $a['empresa'] ?? null, 'empresa_id' => $a['empresa_id'] ?? null, 'material' => $a['material'] ?? null,
            'por' => $a['por'] ?? null, 'medida' => $a['medida'] ?? null, 'agrupar' => $a['agrupar'] ?? null, 'top' => min((int) ($a['top'] ?? 15), 25),
        ], fn ($v) => $v !== null && $v !== '');

        $clase = ReportesController::REPORTES[$tipo];
        /** @var Reporte $reporte */
        $reporte = new $clase($this->usuario, FiltrosDeReporte::desdeElPedido(Request::create('/', 'GET', $parametros)));
        $r = $reporte->generar();

        $columnas = $r['columnas'];
        $formato = function (array $col, array $fila) {
            $v = $fila[$col['clave']] ?? null;

            return match (true) {
                $v === null || $v === '' => '—',
                $col['tipo'] === 'importe' => $this->plata((float) $v),
                $col['tipo'] === 'porcentaje' => number_format((float) $v, 1, ',', '.').' %',
                $col['tipo'] === 'fecha' => $this->fecha((string) $v),
                $col['tipo'] === 'numero' => (int) $v,
                default => (string) $v,
            };
        };
        $filas = array_map(fn ($f) => array_map(fn ($c) => $formato($c, $f), $columnas), array_slice($r['filas'], 0, 25));

        if (is_array($r['totales'] ?? null)) {
            $filas[] = array_map(fn ($c) => $formato($c, $r['totales']), $columnas);
        }

        return [
            'reporte' => $reporte->titulo(), 'periodo' => $r['periodo'], 'filas_totales' => count($r['filas']), 'avisos' => $r['avisos'] ?? [],
            'tablas' => [['titulo' => $reporte->titulo(), 'columnas' => array_column($columnas, 'titulo'), 'filas' => $filas]],
        ];
    }

    /* -------------------------------------------------------------- formatos */

    /** @param  array<string, mixed>  $f */
    private function fila(string $grano, array $f): array
    {
        return match ($grano) {
            'empresas' => [
                'id' => $f['id'], 'nombre' => $f['nombre'], 'cuit' => $f['cuit'] ?? null, 'relaciones' => $f['relaciones'] ?? [], 'localidad' => $f['localidad'] ?? null,
                'provincia' => $f['provincia'] ?? null, 'rubro' => $f['rubro'] ?? null, 'activa' => (bool) ($f['activa'] ?? true), 'cotizaciones' => (int) ($f['cotizaciones'] ?? 0),
                'ultima_cotizacion' => isset($f['ultima_cotizacion']) ? substr((string) $f['ultima_cotizacion'], 0, 10) : null, 'contacto' => $f['contacto_principal']['nombre'] ?? null,
            ],
            'consultas' => [
                'id' => $f['id'], 'numero' => $f['numero'] ?? null, 'tipo' => $f['tipo'], 'estado' => $f['estado'], 'fecha' => $f['fecha'], 'empresa' => $f['empresa']['nombre'] ?? null,
                'empresa_id' => $f['empresa']['id'] ?? null, 'moneda' => $f['moneda'] ?? null, 'vence_el' => $f['vence_el'] ?? null, 'renglones' => $f['cantidad_de_lineas'] ?? null,
                'detalle' => $f['detalle'] ?? [], 'total' => $f['total'] ?? null,
            ],
            'renglones' => [
                'id' => $f['id'], 'cotizacion_id' => $f['consulta']['id'] ?? null, 'numero' => $f['consulta']['numero'] ?? null, 'fecha' => $f['consulta']['fecha'] ?? null,
                'empresa' => $f['empresa']['nombre'] ?? null, 'descripcion' => mb_substr((string) ($f['descripcion'] ?? ''), 0, 110), 'material' => $f['material'] ?? null, 'forma' => $f['forma'] ?? null,
                'cantidad' => $f['cantidad'] ?? null, 'unidad' => $f['unidad'] ?? null, 'precio_unitario' => $f['precio_unitario'] ?? null, 'importe' => $f['importe'] ?? null, 'moneda' => $f['moneda'] ?? null,
            ],
            default => [
                'id' => $f['id'], 'tipo' => $f['tipo'], 'numero' => $f['numero'] ?? null, 'fecha' => $f['fecha'] ?? null, 'importe' => $f['importe'] ?? null,
                'empresa' => $f['empresa']['nombre'] ?? null, 'cotizacion' => $f['consulta']['numero'] ?? null, 'cotizacion_id' => $f['consulta']['id'] ?? null,
            ],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array{titulo: string, columnas: list<string>, filas: list<list<mixed>>, acciones: list<list<array<string, mixed>>>}
     */
    private function tablaDeFilas(string $grano, array $filas, int $total): array
    {
        $titulo = ['empresas' => 'Empresas', 'consultas' => 'Cotizaciones y pedidos', 'renglones' => 'Material cotizado', 'comprobantes' => 'Facturas y remitos'][$grano].($total > count($filas) ? ' (primeras '.count($filas)." de {$total})" : '');
        $ve = $this->usuario->veImportes();
        $d = fn ($v) => $v ?? '—';

        // Un botón por cosa que se puede abrir: así nadie tiene que volver a buscar para llegar a la ficha o a la cotización.
        $acciones = array_map(fn ($f) => match ($grano) {
            'empresas' => array_values(array_filter([['tipo' => 'empresa', 'id' => $f['id']], ($f['activa'] ?? true) ? ['tipo' => 'cotizar', 'id' => $f['id']] : null])),
            'consultas' => array_values(array_filter([['tipo' => 'cotizacion', 'id' => $f['id']], ! empty($f['empresa_id']) ? ['tipo' => 'empresa', 'id' => $f['empresa_id']] : null])),
            default => ! empty($f['cotizacion_id']) ? [['tipo' => 'cotizacion', 'id' => $f['cotizacion_id']]] : [],
        }, $filas);

        $tabla = match ($grano) {
            'empresas' => ['titulo' => $titulo, 'columnas' => ['Empresa', 'Lugar', 'Rubro', 'Cotizaciones', 'Última'],
                'filas' => array_map(fn ($f) => [$f['nombre'].($f['activa'] ? '' : ' (inactiva)'), collect([$f['localidad'], $f['provincia']])->filter()->implode(', ') ?: '—', $d($f['rubro']), $f['cotizaciones'], $f['ultima_cotizacion'] ? $this->fecha($f['ultima_cotizacion']) : '—'], $filas)],
            'consultas' => ['titulo' => $titulo, 'columnas' => array_values(array_filter(['Fecha', 'Número', 'Empresa', 'Tipo', 'Estado', $ve ? 'Total' : null])),
                'filas' => array_map(fn ($f) => array_values(array_filter([$this->fecha($f['fecha']), $d($f['numero']), $f['empresa'], $f['tipo'], $f['estado'], $ve ? ($f['total'] !== null ? $this->plata((float) $f['total']).' '.($f['moneda'] ?? '') : '—') : null], fn ($x) => $x !== null)), $filas)],
            'renglones' => ['titulo' => $titulo, 'columnas' => array_values(array_filter(['Fecha', 'Empresa', 'Descripción', 'Cant.', 'Unidad', $ve ? 'Precio' : null])),
                'filas' => array_map(fn ($f) => array_values(array_filter([$this->fecha($f['fecha']), $f['empresa'], $f['descripcion'], $d($f['cantidad']), $d($f['unidad']), $ve ? ($f['precio_unitario'] !== null ? $this->plata((float) $f['precio_unitario']).' '.($f['moneda'] ?? '') : '—') : null], fn ($x) => $x !== null)), $filas)],
            default => ['titulo' => $titulo, 'columnas' => array_values(array_filter(['Fecha', 'Tipo', 'Número', 'Empresa', $ve ? 'Importe' : null])),
                'filas' => array_map(fn ($f) => array_values(array_filter([$this->fecha($f['fecha']), $f['tipo'], $d($f['numero']), $f['empresa'], $ve ? ($f['importe'] !== null ? $this->plata((float) $f['importe']) : '—') : null], fn ($x) => $x !== null)), $filas)],
        };

        return $tabla + ['acciones' => $acciones];
    }

    private function plata(float $n): string
    {
        return number_format($n, 2, ',', '.');
    }

    private function fecha(?string $iso): string
    {
        return $iso && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : '—';
    }

    /**
     * Si la respuesta es muy grande, se achica: primero las tablas y las filas, después lo demás. La IA ve el
     * `aviso`, así sabe que hay más y no inventa lo que no ve.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function acotar(array $r): array
    {
        $largo = fn () => strlen(json_encode($r, JSON_UNESCAPED_UNICODE) ?: '');

        foreach (['filas', 'empresas', 'ultimos', 'grupos', 'renglones'] as $clave) {
            while ($largo() > self::TOPE_BYTES && isset($r[$clave]) && count($r[$clave]) > 3) {
                $r[$clave] = array_slice($r[$clave], 0, (int) ceil(count($r[$clave]) / 2));
                $r['aviso'] = 'La respuesta se acortó por tamaño: hay más filas de las que ves.';
            }
        }

        foreach ($r['tablas'] ?? [] as $i => $t) {
            while ($largo() > self::TOPE_BYTES && count($r['tablas'][$i]['filas']) > 4) {
                $r['tablas'][$i]['filas'] = array_slice($r['tablas'][$i]['filas'], 0, (int) ceil(count($r['tablas'][$i]['filas']) / 2));
                $r['aviso'] = 'La respuesta se acortó por tamaño: hay más filas de las que ves.';
            }
        }

        return $r;
    }
}
