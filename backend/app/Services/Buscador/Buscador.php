<?php

namespace App\Services\Buscador;

use App\Http\Resources\EmpresaListaResource;
use App\Models\Consulta;
use App\Models\ConsultaComprobante;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Material;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as Eloquent;
use Illuminate\Database\Query\Builder as Consulta_SQL;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * El buscador único: los cuatro buscadores del menú en uno.
 *
 * CUATRO VISTAS del mismo dato. Cada una es su propia consulta, porque mezclarlas
 * en un solo SQL daría filas incompatibles (una empresa no es un renglón):
 *
 *   empresas      una fila por empresa
 *   consultas     una por cotización, pedido u observación
 *   renglones     una por línea de cotización (la de "a quién le coticé tal material")
 *   comprobantes  una por factura, remito, nota de crédito…
 *
 * CUATRO GRUPOS DE FILTROS —de la empresa, de la cotización, del renglón y del
 * comprobante— escritos UNA vez cada uno. Un filtro de otro grupo se traduce a
 * la vista que se está mirando: en "empresas", un filtro de material quiere
 * decir "las empresas a las que les cotizamos ese material".
 *
 * LAS INACTIVAS SE VEN, AL FINAL (pedido del cliente, 04-11-2026): en todas las
 * vistas se ordena primero por si la empresa está activa.
 *
 * LOS IMPORTES son sólo para quien los ve (Quién ve qué): a los demás no les
 * llega ni un precio ni una suma.
 */
class Buscador
{
    /** Lo máximo que baja el Excel: más que esto no es una planilla, es la base. */
    public const TOPE_EXCEL = 10000;

    /** @var array<string, list<int>> */
    private array $materialesPorPalabra = [];

    public function __construct(private readonly User $usuario) {}

    // ------------------------------------------------------------------ salida

    /**
     * Una página de resultados, con su resumen.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>, resumen: array<string, mixed>, permisos: array<string, bool>}
     */
    public function pagina(Filtros $f): array
    {
        $pagina = $f->grano === 'renglones' ? $this->paginaDeRenglones($f) : $this->paraListar($f)->paginate($f->porPagina, ['*'], 'pagina', $f->pagina);

        return [
            'data' => $pagina->getCollection()->map(fn ($fila) => $this->fila($f->grano, $fila))->values()->all(),
            // Los nombres que ya usa el resto del sistema (Paginado, Paginador).
            'meta' => [
                'grano' => $f->grano,
                'current_page' => $pagina->currentPage(),
                'last_page' => $pagina->lastPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
            'resumen' => $this->resumenConMemoria($f),
            'permisos' => ['ve_importes' => $this->veImportes()],
        ];
    }

    /**
     * Los renglones de una página, sin ordenar filas anchas: se ordenan sólo los ids (con lo mínimo que hace falta para ordenar) y recién después
     * se traen completas las de la página. Con 230.000 renglones eso es más del doble de rápido, y la página es exactamente la misma.
     */
    private function paginaDeRenglones(Filtros $f): LengthAwarePaginator
    {
        $total = $this->base($f)->count();
        $ids = $this->ordenarRenglones($this->base($f), $f)->forPage($f->pagina, $f->porPagina)->pluck('consulta_lineas.id')->all();
        $filas = $ids === []
            ? collect()
            : $this->listarRenglones($this->base($f), $f)->whereIn('consulta_lineas.id', $ids)->get()->sortBy(fn ($l) => array_search($l->id, $ids, true))->values();

        return new LengthAwarePaginator($filas, $total, $f->porPagina, $f->pagina, ['pageName' => 'pagina']);
    }

    /**
     * Las cifras de lo que se ve (por estado, plata por moneda) cuestan varias pasadas sobre los datos y cambian poco: se acuerdan dos minutos.
     * La lista y el total NO se acuerdan: siempre son los de ahora.
     *
     * @return array<string, mixed>
     */
    private function resumenConMemoria(Filtros $f): array
    {
        // Las cifras no dependen de qué página, de cuántas por página ni de en qué orden se ve la lista.
        $datos = array_diff_key(get_object_vars($f), ['pagina' => 1, 'porPagina' => 1, 'orden' => 1]);
        $clave = 'buscador.resumen.'.md5(json_encode([$datos, $this->veImportes()]));

        return Cache::remember($clave, 120, fn () => $this->resumen($f));
    }

    /**
     * Las filas para bajar a Excel: todas las que cumplen, hasta el tope.
     *
     * Si hay más que el tope NO se corta en silencio: se avisa, y se pide afinar
     * el filtro. Un Excel con "los primeros 10.000" parece completo y no lo es.
     *
     * @return array{titulos: list<string>, filas: list<list<string|int|float|null>>, total: int}
     */
    public function paraExcel(Filtros $f, int $tope = self::TOPE_EXCEL): array
    {
        $total = $this->base($f)->count();

        abort_if(
            $total > $tope,
            422,
            'Son '.number_format($total, 0, ',', '.').' resultados y el Excel admite hasta '
                .number_format($tope, 0, ',', '.').'. Agregá algún filtro (fechas, empresa, material) para achicar la lista.',
        );

        $filas = [];

        for ($p = 1; ($p - 1) * 500 < $total; $p++) {
            foreach ($this->paraListar($f)->forPage($p, 500)->get() as $fila) {
                $filas[] = $this->filaParaExcel($f->grano, $this->fila($f->grano, $fila));
            }
        }

        return ['titulos' => $this->titulosDeExcel($f->grano), 'filas' => $filas, 'total' => $total];
    }

    private function veImportes(): bool
    {
        return $this->usuario->veImportes();
    }

    // ------------------------------------------------------------------- bases

    /** La consulta con todos los filtros puestos, sin columnas ni orden. */
    private function base(Filtros $f): Eloquent
    {
        return match ($f->grano) {
            'consultas' => $this->baseConsultas($f),
            'renglones' => $this->baseRenglones($f),
            'comprobantes' => $this->baseComprobantes($f),
            default => $this->baseEmpresas($f),
        };
    }

    private function baseEmpresas(Filtros $f): Eloquent
    {
        $q = Empresa::query();
        $this->filtrosDeEmpresa($q, $f);

        foreach ($f->palabras as $p) {
            $q->where(function ($w) use ($p) {
                $w->where('empresas.nombre', 'like', "%{$p}%")
                    ->orWhere('empresas.cuit', 'like', "%{$p}%")
                    ->orWhere('empresas.codigo_isis', 'like', "%{$p}%")
                    ->orWhere('empresas.codigo_indice', 'like', "%{$p}%")
                    ->orWhereHas('contactos.medios', fn ($m) => $m->where('valor', 'like', "%{$p}%"))
                    ->orWhereHas('contactos', fn ($c) => $c->where('nombre', 'like', "%{$p}%"))
                    ->orWhereHas('razonesSociales', fn ($r) => $r->where('razon_social', 'like', "%{$p}%")->orWhere('cuit', 'like', "%{$p}%"))
                    // Como el buscador de siempre: también por lo que se les cotizó. (Por eso se
                    // ordena por relevancia: las que coinciden por nombre van antes.)
                    ->orWhereHas('consultas.lineas', fn ($l) => $l->where('descripcion', 'like', "%{$p}%")->orWhere('pedido_material', 'like', "%{$p}%"));

                // Un CUIT escrito sin guiones tiene que encontrar al que está con guiones.
                $digitos = preg_replace('/\D+/', '', $p) ?? '';

                if (strlen($digitos) >= 8) {
                    $w->orWhereRaw("replace(empresas.cuit, '-', '') like ?", ["%{$digitos}%"]);
                }
            });
        }

        // "Las empresas a las que les cotizamos…": lo de la cotización, el renglón o el comprobante.
        if ($f->hayDeConsulta() || $f->hayDeRenglon() || $f->hayDeComprobante()) {
            $q->whereHas('consultas', function ($c) use ($f) {
                $this->filtrosDeConsulta($c, $f, 'consultas');
                $this->anidados($c, $f);
            });
        }

        return $q;
    }

    private function baseConsultas(Filtros $f): Eloquent
    {
        $q = Consulta::query()->join('empresas as e', 'e.id', '=', 'consultas.empresa_id');

        $this->filtrosDeConsulta($q, $f, 'consultas');
        $this->anidados($q, $f);
        $this->conFiltrosDeEmpresa($q, $f, 'e.id');

        foreach ($f->palabras as $p) {
            $materiales = $this->materialesDe($p);

            $q->where(function ($w) use ($p, $materiales) {
                $w->where('consultas.numero', 'like', "%{$p}%")
                    ->orWhere('consultas.id_sistema', 'like', "%{$p}%")
                    ->orWhere('consultas.solicitud_nro', 'like', "%{$p}%")
                    ->orWhere('consultas.nro_factura', 'like', "%{$p}%")
                    ->orWhere('consultas.nro_remito', 'like', "%{$p}%")
                    ->orWhere('consultas.nro_pedido_interno', 'like', "%{$p}%")
                    ->orWhere('e.nombre', 'like', "%{$p}%")
                    ->orWhereExists(function ($x) use ($p, $materiales) {
                        $x->select(DB::raw(1))->from('consulta_lineas as l')
                            ->whereColumn('l.consulta_id', 'consultas.id')
                            ->where(function ($y) use ($p, $materiales) {
                                $y->where('l.descripcion', 'like', "%{$p}%")
                                    ->orWhere('l.pedido_material', 'like', "%{$p}%")
                                    ->orWhere('l.codigo_cliente', 'like', "%{$p}%")
                                    ->orWhere('l.item_cliente', 'like', "%{$p}%");

                                if ($materiales !== []) {
                                    $y->orWhereIn('l.material_id', $materiales);
                                }
                            });
                    })
                    ->orWhereExists(function ($x) use ($p) {
                        $x->select(DB::raw(1))->from('consulta_comprobantes as k')
                            ->whereColumn('k.consulta_id', 'consultas.id')
                            ->where(fn ($y) => $y->where('k.numero', 'like', "%{$p}%")->orWhere('k.nota', 'like', "%{$p}%"));
                    });
            });
        }

        return $q;
    }

    private function baseRenglones(Filtros $f): Eloquent
    {
        $q = ConsultaLinea::query()
            ->join('consultas as c', 'c.id', '=', 'consulta_lineas.consulta_id')
            ->join('empresas as e', 'e.id', '=', 'c.empresa_id')
            // El join no conoce la papelera: lo de una cotización descartada no cuenta.
            ->whereNull('c.deleted_at');

        $this->filtrosDeRenglon($q, $f, 'consulta_lineas');
        $this->filtrosDeConsulta($q, $f, 'c');
        $this->conFiltrosDeEmpresa($q, $f, 'e.id');

        if ($f->hayDeComprobante()) {
            $q->whereExists(function ($x) use ($f) {
                $x->select(DB::raw(1))->from('consulta_comprobantes as k')->whereColumn('k.consulta_id', 'c.id');
                $this->filtrosDeComprobante($x, $f, 'k');
            });
        }

        foreach ($f->palabras as $p) {
            $materiales = $this->materialesDe($p);

            $q->where(function ($w) use ($p, $materiales) {
                $w->where('consulta_lineas.descripcion', 'like', "%{$p}%")
                    ->orWhere('consulta_lineas.pedido_material', 'like', "%{$p}%")
                    ->orWhere('consulta_lineas.caracteristicas', 'like', "%{$p}%")
                    ->orWhere('consulta_lineas.codigo_cliente', 'like', "%{$p}%")
                    ->orWhere('consulta_lineas.item_cliente', 'like', "%{$p}%")
                    ->orWhere('consulta_lineas.nota', 'like', "%{$p}%")
                    ->orWhere('c.numero', 'like', "%{$p}%")
                    ->orWhere('e.nombre', 'like', "%{$p}%");

                // Por el nombre del material o por cómo lo escribe la gente (el alias).
                if ($materiales !== []) {
                    $w->orWhereIn('consulta_lineas.material_id', $materiales);
                }
            });
        }

        return $q;
    }

    private function baseComprobantes(Filtros $f): Eloquent
    {
        $q = ConsultaComprobante::query()
            ->join('consultas as c', 'c.id', '=', 'consulta_comprobantes.consulta_id')
            ->join('empresas as e', 'e.id', '=', 'c.empresa_id')
            ->whereNull('c.deleted_at');

        $this->filtrosDeComprobante($q, $f, 'consulta_comprobantes');
        $this->filtrosDeConsulta($q, $f, 'c');
        $this->conFiltrosDeEmpresa($q, $f, 'e.id');

        if ($f->hayDeRenglon()) {
            $q->whereExists(function ($x) use ($f) {
                $x->select(DB::raw(1))->from('consulta_lineas as l')->whereColumn('l.consulta_id', 'c.id');
                $this->filtrosDeRenglon($x, $f, 'l');
            });
        }

        foreach ($f->palabras as $p) {
            $q->where(function ($w) use ($p) {
                $w->where('consulta_comprobantes.numero', 'like', "%{$p}%")
                    ->orWhere('consulta_comprobantes.nota', 'like', "%{$p}%")
                    ->orWhere('consulta_comprobantes.tipo', 'like', "%{$p}%")
                    ->orWhere('c.numero', 'like', "%{$p}%")
                    ->orWhere('c.solicitud_nro', 'like', "%{$p}%")
                    ->orWhere('c.nro_factura', 'like', "%{$p}%")
                    ->orWhere('e.nombre', 'like', "%{$p}%");
            });
        }

        return $q;
    }

    // ------------------------------------------------------- los cuatro grupos

    /** Lo de la EMPRESA. Sólo sobre una consulta de Empresa. */
    private function filtrosDeEmpresa(Eloquent $q, Filtros $f): void
    {
        $q->when($f->empresaId, fn ($q, $v) => $q->where('empresas.id', $v))
            ->when($f->empresa, fn ($q, $v) => $q->where('empresas.nombre', 'like', "%{$v}%"))
            ->when($f->activa === 'activas', fn ($q) => $q->where('empresas.activa', true))
            ->when($f->activa === 'inactivas', fn ($q) => $q->where('empresas.activa', false))
            ->conRelacion($f->relacion)
            ->when($f->rubroId, fn ($q, $v) => $q->where('empresas.rubro_id', $v))
            ->when($f->localidadId, fn ($q, $v) => $q->where('empresas.localidad_id', $v))
            ->when($f->provinciaId, fn ($q, $v) => $q->where('empresas.provincia_id', $v))
            ->when($f->condicionIva, fn ($q, $v) => $q->where('empresas.condicion_iva', $v))
            ->when($f->condicionPago, fn ($q, $v) => $q->where('empresas.condicion_pago', 'like', "%{$v}%"))
            // Texto dentro de la observación de la ficha ("recortes", "barra", "planta"…).
            ->when($f->observacion, fn ($q, $v) => $q->where('empresas.observacion_general', 'like', "%{$v}%"))
            ->when($f->condicion, fn ($q, $v) => $q->whereHas(
                'campos',
                fn ($c) => $c->where(fn ($w) => $w->where('titulo', 'like', "%{$v}%")->orWhere('valor', 'like', "%{$v}%"))
            ))
            // Una condición de la lista de la ficha ("Flete") y, si se quiere, su dato ("A cargo del cliente").
            ->when($f->condicionId !== null || $f->opcionId !== null, fn ($q) => $q->whereHas('campos', fn ($c) => $c
                ->when($f->condicionId, fn ($x, $v) => $x->where('tipo_de_condicion_id', $v))
                ->when($f->opcionId, fn ($x, $v) => $x->where('opcion_de_condicion_id', $v))))
            ->when($f->conWhatsapp, fn ($q) => $q->whereHas(
                'contactos.medios.tipoMedio',
                fn ($t) => $t->where('nombre', 'WhatsApp')
            ))
            ->when($f->conMail, fn ($q) => $q->whereHas(
                'contactos.medios.tipoMedio',
                fn ($t) => $t->where('nombre', 'Mail')
            ))
            // "Hace más de N meses que no se les cotiza".
            ->when($f->sinCotizarMeses, fn ($q, $v) => $q->whereDoesntHave(
                'consultas',
                fn ($c) => $c->where('tipo', 'Cotizacion')->whereDate('fecha', '>=', now()->subMonths($v))
            ));
    }

    /** En las otras vistas, lo de la empresa se traduce a "la empresa es una de éstas". */
    private function conFiltrosDeEmpresa(Eloquent $q, Filtros $f, string $columna): void
    {
        if (! $f->hayDeEmpresa()) {
            return;
        }

        $empresas = Empresa::query();
        $this->filtrosDeEmpresa($empresas, $f);

        $q->whereIn($columna, $empresas->select('empresas.id'));
    }

    /**
     * Lo de la COTIZACIÓN. `$t` es cómo se llama su tabla en esta consulta
     * ("consultas", o "c" cuando viene por un join).
     *
     * Sólo usa lo que entienden las dos clases de consulta (la de Eloquent y la
     * de SQL a secas), porque también se aplica adentro de un EXISTS.
     */
    private function filtrosDeConsulta(Eloquent|Consulta_SQL $q, Filtros $f, string $t): void
    {
        $sinEmitir = $f->estado === Consulta::SIN_EMITIR;

        // Los borradores no figuran en el historial: sólo se ven si se piden.
        if (! $f->incluirBorradores && ! $sinEmitir && $f->estado !== 'Borrador') {
            $q->where("{$t}.estado", '!=', 'Borrador');
        }

        // "Sin emitir": los borradores y las guardadas que faltan emitir (lo mismo que cuenta el dashboard).
        if ($sinEmitir) {
            $q->where(fn ($w) => $w->where("{$t}.estado", 'Borrador')
                ->orWhere(fn ($x) => $x->where("{$t}.tipo", 'Cotizacion')->where("{$t}.estado", 'Confirmada')->whereNull("{$t}.emitida_el")));
        }

        $q->when($f->tipo, fn ($q, $v) => $q->where("{$t}.tipo", $v))
            ->when($sinEmitir ? null : $f->estado, fn ($q, $v) => $q->where("{$t}.estado", $v))
            ->when($f->usuarioId, fn ($q, $v) => $q->where("{$t}.usuario_id", $v))
            ->when($f->monedaId, fn ($q, $v) => $q->where("{$t}.moneda_id", $v));

        // Desde / hasta, de la fecha que se elija (la del comprobante va aparte).
        $columna = ['cotizacion' => 'fecha', 'factura' => 'fecha_factura', 'remito' => 'fecha_remito', 'pedido' => 'fecha_pedido'][$f->fechaDe] ?? null;

        if ($columna !== null) {
            $q->when($f->desde, fn ($q, $v) => $q->whereDate("{$t}.{$columna}", '>=', $v))
                ->when($f->hasta, fn ($q, $v) => $q->whereDate("{$t}.{$columna}", '<=', $v));
        }

        if ($f->compra !== null) {
            $q->whereRaw(self::sqlDeCompra($f->compra, $t));
        }

        // La orden de compra del cliente: la de su solicitud, el N° de pedido o un comprobante.
        if ($f->ordenDeCompra !== null) {
            $v = $f->ordenDeCompra;

            $q->where(function ($w) use ($t, $v) {
                $w->where("{$t}.solicitud_nro", 'like', "%{$v}%")
                    ->orWhere("{$t}.nro_pedido_interno", 'like', "%{$v}%")
                    ->orWhereExists(function ($x) use ($t, $v) {
                        $x->select(DB::raw(1))->from('consulta_comprobantes as k')
                            ->whereColumn('k.consulta_id', "{$t}.id")
                            ->where('k.numero', 'like', "%{$v}%");
                    });
            });
        }

        // Pasó la validez y nadie contestó (lo mismo que Consulta::scopeSinRespuesta).
        if ($f->sinRespuesta) {
            $q->whereIn("{$t}.estado", ['Confirmada', Consulta::VENCIDA])
                ->whereNotNull("{$t}.vence_el")
                ->whereDate("{$t}.vence_el", '<', now()->toDateString());
        }

        // Les quedan pocos días (lo mismo que Consulta::scopePorVencer).
        if ($f->porVencer !== null) {
            $q->whereNotNull("{$t}.vence_el")
                ->whereDate("{$t}.vence_el", '>=', now()->toDateString())
                ->whereDate("{$t}.vence_el", '<=', now()->addDays($f->porVencer)->toDateString())
                ->whereNotIn("{$t}.estado", [...Consulta::ESTADOS_DE_CIERRE, 'Vendida']);
        }
    }

    /**
     * Qué se compró de una vendida. La misma regla que Consulta::compra(), en SQL:
     * parcial es cuando hay líneas marcadas como compradas y algún ítem —no una
     * alternativa— quedó sin comprar. Una vendida sin nada marcado es compra total.
     */
    public static function sqlDeCompra(string $cual, string $t): string
    {
        $parcial = "exists (select 1 from consulta_lineas m1 where m1.consulta_id = {$t}.id and m1.quitada = 0 and m1.comprada = 1)"
            ." and exists (select 1 from consulta_lineas m where m.consulta_id = {$t}.id and m.quitada = 0 and m.no_cotizada = 0"
            .' and m.alternativa_de_id is null and m.comprada = 0'
            .' and not exists (select 1 from consulta_lineas a where a.alternativa_de_id = m.id and a.quitada = 0 and a.comprada = 1))';

        return match ($cual) {
            'parcial' => "{$t}.estado = 'Vendida' and ({$parcial})",
            'total' => "{$t}.estado = 'Vendida' and not ({$parcial})",
            default => "{$t}.estado = 'Vendida'",
        };
    }

    /** Lo del RENGLÓN. `$a` es cómo se llama la tabla de líneas en esta consulta. */
    private function filtrosDeRenglon(Eloquent|Consulta_SQL $q, Filtros $f, string $a): void
    {
        // Una línea quitada o que no se cotizó no es algo que se haya cotizado.
        $q->where("{$a}.quitada", false);

        if (! $f->incluirNoCotizadas) {
            $q->where("{$a}.no_cotizada", false);
        }

        if ($f->materialId !== null) {
            $this->materialPorId($q, $f->materialId, $a);
        }

        if ($f->material !== null) {
            $this->materialPorTexto($q, $f->material, $a);
        }

        $q->when($f->formaId, fn ($q, $v) => $q->where("{$a}.forma_id", $v))
            ->when($f->diametroDesde, fn ($q, $v) => $q->where("{$a}.diametro_mm", '>=', $v))
            ->when($f->diametroHasta, fn ($q, $v) => $q->where("{$a}.diametro_mm", '<=', $v));
    }

    /**
     * Un material elegido de la lista.
     *
     * Las líneas del sistema viejo llegaron sin material enlazado: está escrito
     * en el texto. Mientras `lineas:enlazar` no las haya enganchado, un filtro
     * que mirara sólo `material_id` dejaría afuera cotizaciones que SÍ eran de
     * ese material y daría una respuesta incompleta a "a quién le coticé X". Por
     * eso, además del enlace, se miran —sólo en las líneas sin enlazar— el
     * nombre del material y sus alias dentro de lo que pidió el cliente.
     */
    private function materialPorId(Eloquent|Consulta_SQL $q, int $id, string $a): void
    {
        $material = Material::with('alias')->find($id);

        if ($material === null) {
            $q->whereRaw('1 = 0');

            return;
        }

        $terminos = collect([$material->nombre, ...$material->alias->pluck('alias')->all()])
            ->map(fn ($t) => trim((string) $t))
            ->filter(fn ($t) => mb_strlen($t) >= 3)
            ->unique()
            ->take(12)
            ->values();

        $q->where(function ($w) use ($material, $terminos, $a) {
            $w->where("{$a}.material_id", $material->id);

            if ($terminos->isNotEmpty()) {
                $w->orWhere(function ($sin) use ($terminos, $a) {
                    $sin->whereNull("{$a}.material_id")->where(function ($t) use ($terminos, $a) {
                        foreach ($terminos as $x) {
                            $t->orWhere("{$a}.pedido_material", 'like', "%{$x}%")
                                ->orWhere("{$a}.descripcion", 'like', "%{$x}%");
                        }
                    });
                });
            }
        });
    }

    /** Un material escrito: por nombre y alias del catálogo, o como lo escribió el cliente. */
    private function materialPorTexto(Eloquent|Consulta_SQL $q, string $texto, string $a): void
    {
        $ids = $this->materialesDe($texto);

        $q->where(function ($w) use ($ids, $texto, $a) {
            if ($ids !== []) {
                $w->orWhereIn("{$a}.material_id", $ids);
            }

            $w->orWhere("{$a}.pedido_material", 'like', "%{$texto}%")
                ->orWhere(fn ($sin) => $sin->whereNull("{$a}.material_id")->where("{$a}.descripcion", 'like', "%{$texto}%"));
        });
    }

    /** Lo del COMPROBANTE. `$k` es cómo se llama su tabla en esta consulta. */
    private function filtrosDeComprobante(Eloquent|Consulta_SQL $q, Filtros $f, string $k): void
    {
        $q->when($f->comprobante, fn ($q, $v) => $q->where("{$k}.numero", 'like', "%{$v}%"))
            ->when($f->tipoComprobante, fn ($q, $v) => $q->where("{$k}.tipo", $v));

        if ($f->fechaDe === 'comprobante') {
            $q->when($f->desde, fn ($q, $v) => $q->whereDate("{$k}.fecha", '>=', $v))
                ->when($f->hasta, fn ($q, $v) => $q->whereDate("{$k}.fecha", '<=', $v));
        }
    }

    /** Lo del renglón y del comprobante, mirado desde una cotización. */
    private function anidados(Eloquent $q, Filtros $f): void
    {
        if ($f->hayDeRenglon()) {
            $q->whereHas('lineas', fn ($l) => $this->filtrosDeRenglon($l, $f, 'consulta_lineas'));
        }

        if ($f->hayDeComprobante()) {
            $q->whereHas('comprobantes', fn ($k) => $this->filtrosDeComprobante($k, $f, 'consulta_comprobantes'));
        }
    }

    /**
     * Los materiales del catálogo que una palabra nombra, por su nombre o por un
     * alias: "monel" encuentra los renglones cuyo material es un Monel aunque la
     * descripción no lo diga. Con menos de tres letras no se adivina nada.
     *
     * @return list<int>
     */
    private function materialesDe(string $palabra): array
    {
        if (mb_strlen($palabra) < 3) {
            return [];
        }

        return $this->materialesPorPalabra[$palabra] ??= Material::buscar($palabra)->limit(300)->pluck('id')->all();
    }

    // ------------------------------------------------ columnas, orden y página

    /** La consulta lista para paginar: con sus columnas, lo que se carga y el orden. */
    private function paraListar(Filtros $f): Eloquent
    {
        $q = $this->base($f);

        return match ($f->grano) {
            'consultas' => $this->listarConsultas($q, $f),
            'renglones' => $this->listarRenglones($q, $f),
            'comprobantes' => $this->listarComprobantes($q, $f),
            default => $this->listarEmpresas($q, $f),
        };
    }

    private function listarEmpresas(Eloquent $q, Filtros $f): Eloquent
    {
        $q->with(['relaciones', 'contactos', 'razonesSociales', 'localidad', 'provincia', 'rubro'])
            ->withCount(['consultas as consultas_count' => fn ($c) => $c->where('tipo', 'Cotizacion')->where('estado', '!=', 'Borrador')])
            ->withMax(['consultas as ultima_cotizacion' => fn ($c) => $c->where('estado', '!=', 'Borrador')], 'fecha')
            // Las que cerraron, al final.
            ->orderByDesc('empresas.activa');

        if ($f->orden === 'relevancia' && $f->q !== null) {
            // Primero las que coinciden por NOMBRE: empieza con, después contiene.
            $q->orderByRaw(
                'case when empresas.nombre like ? then 1 when empresas.nombre like ? then 2 else 3 end',
                [$f->q.'%', '%'.$f->q.'%'],
            );
        } elseif ($f->orden === 'reciente') {
            $q->orderByRaw('ultima_cotizacion is null')->orderByDesc('ultima_cotizacion');
        } elseif ($f->orden === 'antiguo') {
            $q->orderByRaw('ultima_cotizacion is null')->orderBy('ultima_cotizacion');
        }

        return $q->orderBy('empresas.nombre')->orderBy('empresas.id');
    }

    private function listarConsultas(Eloquent $q, Filtros $f): Eloquent
    {
        $q->select('consultas.*')
            ->addSelect(['e.nombre as empresa_nombre', 'e.activa as empresa_activa'])
            ->with(['lineas', 'comprobantes:id,consulta_id,tipo,numero', 'moneda:id,nombre', 'usuario'])
            ->orderByDesc('e.activa');

        if ($f->orden === 'nombre') {
            $q->orderBy('e.nombre');
        }

        $sentido = $f->orden === 'antiguo' ? 'asc' : 'desc';

        return $q->orderBy('consultas.fecha', $sentido)->orderBy('consultas.id', $sentido);
    }

    private function listarRenglones(Eloquent $q, Filtros $f): Eloquent
    {
        $q->leftJoin('monedas as mo', 'mo.id', '=', 'c.moneda_id')
            ->select('consulta_lineas.*')
            ->addSelect([
                'c.id as c_id', 'c.numero as c_numero', 'c.revision as c_revision', 'c.tipo as c_tipo',
                'c.estado as c_estado', 'c.fecha as c_fecha', 'c.emitida_el as c_emitida_el',
                'e.id as empresa_id', 'e.nombre as empresa_nombre', 'e.activa as empresa_activa',
                'mo.nombre as moneda',
            ])
            ->with(['material:id,nombre', 'forma:id,nombre', 'unidadVenta:id,codigo']);

        return $this->ordenarRenglones($q, $f);
    }

    /** El orden de los renglones (las empresas dadas de baja, al final); sin tocar qué columnas se traen. */
    private function ordenarRenglones(Eloquent $q, Filtros $f): Eloquent
    {
        $q->orderByDesc('e.activa');

        if ($f->orden === 'nombre') {
            $q->orderBy('e.nombre');
        } elseif ($f->orden === 'importe') {
            $q->orderByDesc('consulta_lineas.importe');
        }

        $sentido = $f->orden === 'antiguo' ? 'asc' : 'desc';

        return $q->orderBy('c.fecha', $sentido)->orderBy('c.id', $sentido)->orderBy('consulta_lineas.orden')->orderBy('consulta_lineas.id');
    }

    private function listarComprobantes(Eloquent $q, Filtros $f): Eloquent
    {
        $q->leftJoin('monedas as mo', 'mo.id', '=', 'c.moneda_id')
            ->select('consulta_comprobantes.*')
            ->addSelect([
                'c.id as c_id', 'c.numero as c_numero', 'c.revision as c_revision', 'c.tipo as c_tipo',
                'c.estado as c_estado', 'c.fecha as c_fecha', 'c.solicitud_nro as c_solicitud_nro',
                'c.emitida_el as c_emitida_el',
                'e.id as empresa_id', 'e.nombre as empresa_nombre', 'e.activa as empresa_activa',
                'mo.nombre as moneda',
            ])
            ->orderByDesc('e.activa');

        if ($f->orden === 'nombre') {
            $q->orderBy('e.nombre');
        } elseif ($f->orden === 'importe') {
            $q->orderByRaw('consulta_comprobantes.importe is null')->orderByDesc('consulta_comprobantes.importe');
        }

        $sentido = $f->orden === 'antiguo' ? 'asc' : 'desc';

        // Sin fecha, al final.
        return $q->orderByRaw('consulta_comprobantes.fecha is null')
            ->orderBy('consulta_comprobantes.fecha', $sentido)
            ->orderBy('consulta_comprobantes.id', $sentido);
    }

    // -------------------------------------------------------------------- filas

    /** @return array<string, mixed> */
    private function fila(string $grano, mixed $r): array
    {
        return match ($grano) {
            'consultas' => $this->filaDeConsulta($r),
            'renglones' => $this->filaDeRenglon($r),
            'comprobantes' => $this->filaDeComprobante($r),
            default => $this->filaDeEmpresa($r),
        };
    }

    /** @return array<string, mixed> */
    private function filaDeEmpresa(Empresa $e): array
    {
        return [
            ...(new EmpresaListaResource($e))->toArray(request()),
            'ultima_cotizacion' => $e->ultima_cotizacion,
        ];
    }

    /** @return array<string, mixed> */
    private function filaDeConsulta(Consulta $c): array
    {
        $ve = $this->veImportes();
        $lineas = $c->lineas->where('quitada', false);
        $compra = $c->compra();

        return [
            'id' => $c->id,
            'tipo' => $c->tipo,
            'estado' => $c->estado,
            'fecha' => $c->fecha?->format('Y-m-d'),
            'numero' => $c->numeroConRevision(),
            'emitida' => $c->estaEmitida(),
            'empresa' => ['id' => $c->empresa_id, 'nombre' => $c->empresa_nombre, 'activa' => (bool) $c->empresa_activa],
            'moneda' => $c->moneda?->nombre,
            'usuario' => $c->usuario?->initials,
            'vence_el' => $c->vence_el?->format('Y-m-d'),
            'esta_vencida' => $c->esta_vencida,
            'solicitud_nro' => $c->solicitud_nro,
            'nro_factura' => $c->nro_factura
                ?? ($c->comprobantes->filter(fn ($k) => str_starts_with($k->tipo, 'Factura') && filled($k->numero))->pluck('numero')->implode(', ') ?: null),
            'nro_remito' => $c->nro_remito,
            'nro_pedido_interno' => $c->nro_pedido_interno,
            'comprobantes' => $c->comprobantes->map(fn ($k) => trim($k->tipo.' '.$k->numero))->values()->all(),
            'cantidad_de_lineas' => $lineas->count(),
            'detalle' => $lineas->take(2)->pluck('descripcion')->values()->all(),
            'compra' => $compra === null ? null : [
                'parcial' => $compra['parcial'], 'compradas' => $compra['compradas'], 'total' => $compra['total'],
            ] + ($ve ? ['importe' => $compra['importe']] : []),
            'total' => $ve ? $c->total : null,
        ];
    }

    /** @return array<string, mixed> */
    private function filaDeRenglon(ConsultaLinea $l): array
    {
        $ve = $this->veImportes();

        return [
            'id' => $l->id,
            'consulta' => [
                'id' => $l->c_id,
                'numero' => filled($l->c_numero) ? $l->c_numero.' R'.(int) $l->c_revision : null,
                'tipo' => $l->c_tipo,
                'estado' => $l->c_estado,
                'fecha' => $l->c_fecha ? substr((string) $l->c_fecha, 0, 10) : null,
                'emitida' => $l->c_emitida_el !== null,
            ],
            'empresa' => ['id' => $l->empresa_id, 'nombre' => $l->empresa_nombre, 'activa' => (bool) $l->empresa_activa],
            'moneda' => $l->moneda,
            'es_alternativa' => $l->alternativa_de_id !== null,
            'descripcion' => $l->descripcion,
            'material' => $l->material?->nombre,
            'pedido_material' => $l->pedido_material,
            'forma' => $l->forma?->nombre,
            'diametro_mm' => $l->diametro_mm !== null ? (float) $l->diametro_mm : null,
            'espesor_mm' => $l->espesor_mm !== null ? (float) $l->espesor_mm : null,
            'ancho_mm' => $l->ancho_mm !== null ? (float) $l->ancho_mm : null,
            'largo_mm' => $l->largo_mm !== null ? (float) $l->largo_mm : null,
            'cantidad' => $l->cantidad !== null ? (float) $l->cantidad : null,
            'unidad' => $l->unidadVenta?->codigo,
            'precio_unitario' => $ve && $l->precio_unitario !== null ? (float) $l->precio_unitario : null,
            'importe' => $ve && $l->importe !== null ? (float) $l->importe : null,
            'comprada' => (bool) $l->comprada,
            'no_cotizada' => (bool) $l->no_cotizada,
            'item_cliente' => $l->item_cliente,
            'codigo_cliente' => $l->codigo_cliente,
        ];
    }

    /** @return array<string, mixed> */
    private function filaDeComprobante(ConsultaComprobante $k): array
    {
        return [
            'id' => $k->id,
            'tipo' => $k->tipo,
            'numero' => $k->numero,
            'fecha' => $k->fecha?->format('Y-m-d'),
            'importe' => $this->veImportes() && $k->importe !== null ? (float) $k->importe : null,
            'nota' => $k->nota,
            'moneda' => $k->moneda,
            'consulta' => [
                'id' => $k->c_id,
                'numero' => filled($k->c_numero) ? $k->c_numero.' R'.(int) $k->c_revision : null,
                'tipo' => $k->c_tipo,
                'estado' => $k->c_estado,
                'fecha' => $k->c_fecha ? substr((string) $k->c_fecha, 0, 10) : null,
                'solicitud_nro' => $k->c_solicitud_nro,
                'emitida' => $k->c_emitida_el !== null,
            ],
            'empresa' => ['id' => $k->empresa_id, 'nombre' => $k->empresa_nombre, 'activa' => (bool) $k->empresa_activa],
        ];
    }

    // ------------------------------------------------------------------ resumen

    /**
     * Lo que dicen los números de TODO el resultado, no de la página que se ve.
     * (Los totales de "Consultas por fecha" se calculaban sólo sobre las 25 filas
     * de la pantalla, y por eso no eran los del período.)
     *
     * @return array<string, mixed>
     */
    /** Las cifras de lo que se está viendo (por estado, por moneda…), sin armar la página. Lo usa Cordes IA. */
    public function resumenDe(Filtros $f): array
    {
        return $this->resumen($f);
    }

    private function resumen(Filtros $f): array
    {
        $ve = $this->veImportes();
        $base = $this->base($f);

        switch ($f->grano) {
            case 'consultas':
                $porEstado = (clone $base)->reorder()->toBase()
                    ->selectRaw('consultas.estado as clave, count(*) as n')->groupBy('consultas.estado')
                    ->pluck('n', 'clave')->map(fn ($n) => (int) $n)->all();

                return ['por_estado' => $porEstado]
                    + ($ve ? ['importe_por_moneda' => $this->importesDeLasLineas((clone $base)->reorder()->select('consultas.id'))] : []);

            case 'renglones':
                return $ve ? ['importe_por_moneda' => $this->importesDeRenglones($base)] : [];

            case 'comprobantes':
                $porTipo = (clone $base)->reorder()->toBase()
                    ->selectRaw('consulta_comprobantes.tipo as clave, count(*) as n')->groupBy('consulta_comprobantes.tipo')
                    ->pluck('n', 'clave')->map(fn ($n) => (int) $n)->all();

                return ['por_tipo' => $porTipo] + ($ve ? ['importe_por_moneda' => $this->importesDeComprobantes($base)] : []);

            default:
                $porActividad = (clone $base)->reorder()->toBase()
                    ->selectRaw('empresas.activa as clave, count(*) as n')->groupBy('empresas.activa')
                    ->pluck('n', 'clave')->all();

                return ['activas' => (int) ($porActividad[1] ?? 0), 'inactivas' => (int) ($porActividad[0] ?? 0)];
        }
    }

    /**
     * Lo cotizado en esas cotizaciones, por moneda: lo que suma el total de cada
     * una (sin alternativas, sin renglones quitados ni sin cotizar).
     *
     * @return list<array{moneda: ?string, importe: float, cotizaciones: int}>
     */
    private function importesDeLasLineas(Eloquent $ids): array
    {
        return DB::table('consulta_lineas as l')
            ->join('consultas as c', 'c.id', '=', 'l.consulta_id')
            ->leftJoin('monedas as mo', 'mo.id', '=', 'c.moneda_id')
            ->whereIn('l.consulta_id', $ids)
            ->where('l.quitada', false)->where('l.no_cotizada', false)->whereNull('l.alternativa_de_id')
            ->groupBy('mo.nombre')
            ->selectRaw('mo.nombre as moneda, sum(l.importe) as importe, count(distinct l.consulta_id) as cotizaciones')
            ->get()
            ->map(fn ($r) => ['moneda' => $r->moneda, 'importe' => round((float) $r->importe, 2), 'cotizaciones' => (int) $r->cotizaciones])
            ->values()->all();
    }

    /** @return list<array{moneda: ?string, importe: float, renglones: int}> */
    private function importesDeRenglones(Eloquent $base): array
    {
        return (clone $base)->reorder()->leftJoin('monedas as mo', 'mo.id', '=', 'c.moneda_id')
            // Las alternativas se listan pero no suman: se ofrecen para elegir una.
            ->whereNull('consulta_lineas.alternativa_de_id')->where('consulta_lineas.no_cotizada', false)
            ->toBase()
            ->groupBy('mo.nombre')
            ->selectRaw('mo.nombre as moneda, sum(consulta_lineas.importe) as importe, count(*) as renglones')
            ->get()
            ->map(fn ($r) => ['moneda' => $r->moneda, 'importe' => round((float) $r->importe, 2), 'renglones' => (int) $r->renglones])
            ->values()->all();
    }

    /** @return list<array{moneda: ?string, importe: float, comprobantes: int}> */
    private function importesDeComprobantes(Eloquent $base): array
    {
        return (clone $base)->reorder()->leftJoin('monedas as mo', 'mo.id', '=', 'c.moneda_id')
            ->whereNotNull('consulta_comprobantes.importe')
            ->toBase()
            ->groupBy('mo.nombre')
            ->selectRaw('mo.nombre as moneda, sum(consulta_comprobantes.importe) as importe, count(*) as comprobantes')
            ->get()
            ->map(fn ($r) => ['moneda' => $r->moneda, 'importe' => round((float) $r->importe, 2), 'comprobantes' => (int) $r->comprobantes])
            ->values()->all();
    }

    // ------------------------------------------------------- para Cordes IA

    /** Cuántas filas da esta búsqueda, sin traerlas. */
    public function contar(Filtros $f): int
    {
        return $this->base($f)->count();
    }

    /** Las maneras de agrupar que entiende `agrupar`, por vista. */
    public const AGRUPAR = [
        'empresas' => ['provincia', 'localidad', 'rubro', 'activa'],
        'consultas' => ['empresa', 'estado', 'tipo', 'mes', 'anio', 'usuario', 'moneda', 'provincia'],
        'renglones' => ['empresa', 'material', 'forma', 'estado', 'mes', 'anio', 'moneda', 'provincia'],
        'comprobantes' => ['tipo', 'empresa', 'mes', 'anio'],
    ];

    /**
     * Cuántas filas hay de cada cosa: por provincia, por empresa, por mes, por material…
     * Es lo que responde "dónde están", "quién más", "cómo viene por año". Cuenta; no suma plata.
     *
     * @return list<array{clave: string, etiqueta: string, n: int}>
     *
     * @throws \InvalidArgumentException con las maneras que sí se pueden, si no es una de ellas
     */
    public function agrupar(Filtros $f, string $por, int $max = 10): array
    {
        $permitidas = self::AGRUPAR[$f->grano] ?? [];

        if (! in_array($por, $permitidas, true)) {
            throw new \InvalidArgumentException("En «{$f->grano}» se puede agrupar por: ".implode(', ', $permitidas));
        }

        $sqlite = DB::getDriverName() === 'sqlite';
        $fecha = fn (string $col, string $formato) => $sqlite
            ? "strftime('".($formato === 'mes' ? '%Y-%m' : '%Y')."', {$col})"
            : "date_format({$col}, '".($formato === 'mes' ? '%Y-%m' : '%Y')."')";

        $q = $this->base($f)->reorder()->toBase();
        $e = $f->grano === 'empresas' ? 'empresas' : 'e';
        $c = match ($f->grano) {
            'consultas' => 'consultas',
            'empresas' => null,
            default => 'c',
        };

        // [expresión de la clave, expresión de la etiqueta]
        [$clave, $etiqueta] = match ($por) {
            'provincia' => $this->unir($q, 'provincias', 'p', "p.id = {$e}.provincia_id", 'p.id', 'p.nombre'),
            'localidad' => $this->unir($q, 'localidades', 'lo', "lo.id = {$e}.localidad_id", 'lo.id', 'lo.nombre'),
            'rubro' => $this->unir($q, 'rubros', 'ru', "ru.id = {$e}.rubro_id", 'ru.id', 'ru.nombre'),
            'activa' => ["{$e}.activa", "case when {$e}.activa then 'Activas' else 'Inactivas' end"],
            'empresa' => ["{$e}.id", "{$e}.nombre"],
            'estado' => ["{$c}.estado", "{$c}.estado"],
            'tipo' => $f->grano === 'comprobantes' ? ['consulta_comprobantes.tipo', 'consulta_comprobantes.tipo'] : ["{$c}.tipo", "{$c}.tipo"],
            'usuario' => $this->unir($q, 'users', 'u', "u.id = {$c}.usuario_id", 'u.id', 'coalesce(u.iniciales, u.name)'),
            'moneda' => $this->unir($q, 'monedas', 'mo', "mo.id = {$c}.moneda_id", 'mo.id', 'mo.nombre'),
            'material' => $this->unir($q, 'materiales', 'ma', 'ma.id = consulta_lineas.material_id', 'ma.id', 'ma.nombre'),
            'forma' => $this->unir($q, 'formas', 'fo', 'fo.id = consulta_lineas.forma_id', 'fo.id', 'fo.nombre'),
            'mes', 'anio' => (function () use ($f, $c, $fecha, $por) {
                $col = $f->grano === 'comprobantes' ? 'consulta_comprobantes.fecha' : "{$c}.fecha";
                $x = $fecha($col, $por);

                return [$x, $x];
            })(),
        };

        return $q->selectRaw("{$clave} as clave, {$etiqueta} as etiqueta, count(*) as n")
            ->groupByRaw("{$clave}, {$etiqueta}")
            ->when(in_array($por, ['mes', 'anio'], true), fn ($x) => $x->orderByRaw("{$etiqueta} desc"), fn ($x) => $x->orderByDesc('n')->orderByRaw('etiqueta'))
            ->limit(max(1, min($max, 50)))
            ->get()
            ->map(fn ($r) => ['clave' => (string) ($r->clave ?? ''), 'etiqueta' => (string) ($r->etiqueta ?? 'Sin dato'), 'n' => (int) $r->n])
            ->values()->all();
    }

    /** @return array{0: string, 1: string} */
    private function unir(Consulta_SQL $q, string $tabla, string $alias, string $on, string $clave, string $etiqueta): array
    {
        $q->leftJoin("{$tabla} as {$alias}", fn ($j) => $j->whereRaw($on));

        return [$clave, $etiqueta];
    }

    /**
     * Lo que se cotizó por unidad, para saber "a cuánto". Estadística por moneda y unidad de venta (nunca se
     * mezclan dólares con pesos ni kilos con piezas) y los últimos renglones. Sólo para quien ve importes.
     *
     * @return array{estadisticas: list<array<string, mixed>>, ultimos: list<array<string, mixed>>, renglones: int}
     */
    public function precios(Filtros $f, int $ultimos = 8): array
    {
        abort_unless($this->veImportes(), 403, 'No tenés permiso para ver precios.');

        $base = fn () => $this->baseRenglones($f)->reorder()->toBase()
            ->leftJoin('monedas as mo', 'mo.id', '=', 'c.moneda_id')
            ->leftJoin('unidades as un', 'un.id', '=', 'consulta_lineas.unidad_venta_id')
            // Las alternativas se ofrecen para elegir una: no son un precio cotizado.
            ->whereNull('consulta_lineas.alternativa_de_id')
            ->where('consulta_lineas.precio_unitario', '>', 0);

        // Mínimo, máximo, promedio y cuántos renglones: sobre TODOS, en la base (con muchos datos no se traen a PHP).
        $exactas = $base()->groupBy('mo.nombre', 'un.codigo')
            ->selectRaw('mo.nombre as moneda, un.codigo as unidad, count(*) as n, min(consulta_lineas.precio_unitario) as minimo, max(consulta_lineas.precio_unitario) as maximo, avg(consulta_lineas.precio_unitario) as promedio')
            ->get();

        // La mediana y el último precio salen de los más recientes (hasta 5.000): si hay más, la mediana es de esos.
        // De ese mismo conjunto, ya ordenado, salen también las últimas cotizaciones que se muestran (una pasada menos sobre los renglones).
        $filas = $base()->orderByDesc('c.fecha')->orderByDesc('consulta_lineas.id')->limit(5000)
            ->get(['mo.nombre as moneda', 'un.codigo as unidad', 'consulta_lineas.precio_unitario as precio', 'consulta_lineas.precio_por_kilo as por_kilo', 'c.fecha as fecha',
                'c.id as consulta_id', 'c.numero', 'c.revision', 'e.nombre as empresa', 'consulta_lineas.descripcion', 'consulta_lineas.cantidad']);

        $grupos = [];

        foreach ($filas as $r) {
            $grupos[($r->moneda ?? 'Sin moneda').'|'.($r->unidad ?? '—')][] = $r;
        }

        $estadisticas = [];
        $total = 0;

        foreach ($exactas as $e) {
            $clave = ($e->moneda ?? 'Sin moneda').'|'.($e->unidad ?? '—');
            $rs = $grupos[$clave] ?? [];
            $n = (int) $e->n;
            $total += $n;
            $precios = array_map(fn ($r) => (float) $r->precio, $rs);
            sort($precios);
            $m = count($precios);
            $mediana = $m === 0 ? null : ($m % 2 ? $precios[intdiv($m, 2)] : ($precios[$m / 2 - 1] + $precios[$m / 2]) / 2);
            $porKilo = array_values(array_filter(array_map(fn ($r) => $r->por_kilo !== null && (float) $r->por_kilo > 0 ? (float) $r->por_kilo : null, $rs)));

            $estadisticas[] = [
                'moneda' => $e->moneda ?? 'Sin moneda', 'unidad' => $e->unidad ?? '—', 'renglones' => $n,
                'minimo' => round((float) $e->minimo, 2), 'maximo' => round((float) $e->maximo, 2), 'promedio' => round((float) $e->promedio, 2),
                'mediana' => $mediana === null ? null : round($mediana, 2), 'mediana_de_los_ultimos' => $m < $n ? $m : null,
                'ultimo_precio' => $rs === [] ? null : round((float) $rs[0]->precio, 2), 'ultima_fecha' => $rs === [] ? null : substr((string) $rs[0]->fecha, 0, 10),
                'por_kilo_promedio' => $porKilo === [] ? null : round(array_sum($porKilo) / count($porKilo), 2),
            ];
        }

        usort($estadisticas, fn ($a, $b) => $b['renglones'] <=> $a['renglones']);

        $recientes = $filas->take(max(1, min($ultimos, 20)))
            ->map(fn ($r) => [
                'fecha' => substr((string) $r->fecha, 0, 10),
                'cotizacion_id' => (int) $r->consulta_id,
                'numero' => filled($r->numero) ? $r->numero.' R'.(int) $r->revision : null,
                'empresa' => $r->empresa,
                'descripcion' => mb_substr((string) $r->descripcion, 0, 110),
                'cantidad' => $r->cantidad !== null ? (float) $r->cantidad : null,
                'unidad' => $r->unidad,
                'precio' => round((float) $r->precio, 2),
                'por_kilo' => $r->por_kilo !== null && (float) $r->por_kilo > 0 ? round((float) $r->por_kilo, 2) : null,
                'moneda' => $r->moneda,
            ])->values()->all();

        return ['estadisticas' => $estadisticas, 'ultimos' => $recientes, 'renglones' => $total];
    }

    /**
     * Cómo se movió el precio: mínimo, promedio y máximo por año (o por mes), separados por moneda y unidad de venta
     * (nunca se mezclan). Los más recientes primero. Sólo para quien ve importes.
     *
     * @return list<array{periodo: string, moneda: string, unidad: string, renglones: int, minimo: float, promedio: float, maximo: float}>
     */
    public function evolucionDePrecios(Filtros $f, string $por = 'anio', int $max = 40): array
    {
        abort_unless($this->veImportes(), 403, 'No tenés permiso para ver precios.');

        $formato = $por === 'mes' ? '%Y-%m' : '%Y';
        $periodo = DB::getDriverName() === 'sqlite' ? "strftime('{$formato}', c.fecha)" : "date_format(c.fecha, '{$formato}')";

        return $this->baseRenglones($f)->reorder()->toBase()
            ->leftJoin('monedas as mo', 'mo.id', '=', 'c.moneda_id')
            ->leftJoin('unidades as un', 'un.id', '=', 'consulta_lineas.unidad_venta_id')
            ->whereNull('consulta_lineas.alternativa_de_id')
            ->where('consulta_lineas.precio_unitario', '>', 0)
            ->whereNotNull('c.fecha')
            ->groupByRaw("{$periodo}, mo.nombre, un.codigo")
            ->selectRaw("{$periodo} as periodo, mo.nombre as moneda, un.codigo as unidad, count(*) as n, min(consulta_lineas.precio_unitario) as minimo, "
                .'avg(consulta_lineas.precio_unitario) as promedio, max(consulta_lineas.precio_unitario) as maximo')
            ->orderByRaw("{$periodo} desc")->orderByDesc('n')
            ->limit(max(1, min($max, 60)))
            ->get()
            ->map(fn ($r) => [
                'periodo' => (string) $r->periodo, 'moneda' => $r->moneda ?? 'Sin moneda', 'unidad' => $r->unidad ?? '—', 'renglones' => (int) $r->n,
                'minimo' => round((float) $r->minimo, 2), 'promedio' => round((float) $r->promedio, 2), 'maximo' => round((float) $r->maximo, 2),
            ])->values()->all();
    }

    // -------------------------------------------------------------------- Excel

    /** @return list<string> */
    private function titulosDeExcel(string $grano): array
    {
        $ve = $this->veImportes();

        return match ($grano) {
            'consultas' => ['N°', 'Tipo', 'Estado', 'Fecha', 'Empresa', 'Empresa activa', 'Hizo', 'Moneda',
                ...($ve ? ['Total'] : []), 'Orden de compra', 'Factura', 'Remito', 'Pedido', 'Comprobantes', 'Qué se cotizó'],
            'renglones' => ['Fecha', 'N°', 'Tipo', 'Estado', 'Empresa', 'Empresa activa', 'Material', 'Forma', 'Descripción',
                'Diámetro (mm)', 'Largo (mm)', 'Cantidad', 'Unidad', ...($ve ? ['Precio unitario', 'Importe'] : []),
                'Moneda', 'Alternativa', 'Comprada', 'Su ítem'],
            'comprobantes' => ['Fecha', 'Tipo', 'Número', ...($ve ? ['Importe'] : []), 'Moneda', 'Empresa', 'Empresa activa',
                'Cotización N°', 'Estado', 'Orden de compra', 'Nota'],
            default => ['Empresa', 'Activa', 'CUIT', 'Relaciones', 'Rubro', 'Localidad', 'Provincia', 'Contacto principal',
                'Cotizaciones', 'Última cotización'],
        };
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<string|int|float|null>
     */
    private function filaParaExcel(string $grano, array $r): array
    {
        $ve = $this->veImportes();
        $si = fn ($v) => $v ? 'Sí' : 'No';
        $dia = fn (?string $d) => $d ? date('d/m/Y', (int) strtotime($d)) : null;

        return match ($grano) {
            'consultas' => [
                $r['numero'], $r['tipo'], $r['estado'], $dia($r['fecha']), $r['empresa']['nombre'], $si($r['empresa']['activa']),
                $r['usuario'], $r['moneda'], ...($ve ? [$r['total']] : []), $r['solicitud_nro'], $r['nro_factura'],
                $r['nro_remito'], $r['nro_pedido_interno'], implode(' · ', $r['comprobantes']), implode(' | ', $r['detalle']),
            ],
            'renglones' => [
                $dia($r['consulta']['fecha']), $r['consulta']['numero'], $r['consulta']['tipo'], $r['consulta']['estado'],
                $r['empresa']['nombre'], $si($r['empresa']['activa']), $r['material'] ?? $r['pedido_material'], $r['forma'],
                $r['descripcion'], $r['diametro_mm'], $r['largo_mm'], $r['cantidad'], $r['unidad'],
                ...($ve ? [$r['precio_unitario'], $r['importe']] : []),
                $r['moneda'], $si($r['es_alternativa']), $si($r['comprada']), $r['item_cliente'],
            ],
            'comprobantes' => [
                $dia($r['fecha']), $r['tipo'], $r['numero'], ...($ve ? [$r['importe']] : []), $r['moneda'],
                $r['empresa']['nombre'], $si($r['empresa']['activa']), $r['consulta']['numero'], $r['consulta']['estado'],
                $r['consulta']['solicitud_nro'], $r['nota'],
            ],
            default => [
                $r['nombre'], $si($r['activa']), $r['cuit'], implode(' · ', $r['relaciones']->all()), $r['rubro'],
                $r['localidad'], $r['provincia'], $r['contacto_principal']['nombre'] ?? null, $r['cotizaciones'],
                $dia($r['ultima_cotizacion']),
            ],
        };
    }
}
