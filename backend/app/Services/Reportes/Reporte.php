<?php

namespace App\Services\Reportes;

use App\Models\Moneda;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Un reporte: de la base a una tabla lista para mirar, bajar a Excel o llevar a una reunión.
 *
 * TODOS TIENEN LA MISMA FORMA —columnas, filas, totales, avisos—, así una sola pantalla y un solo
 * Excel sirven para todos. Lo que cambia es la pregunta que responden.
 *
 * REGLAS QUE COMPARTEN
 *  · Las cuentas las hace la base (GROUP BY), no se traen miles de filas para sumar acá.
 *  · Los importes se separan POR MONEDA: nunca se suman dólares con pesos. Cada moneda es una columna.
 *  · Sólo cotizaciones firmes: sin borradores, sin las descartadas, sin pedidos ni observaciones.
 *  · "Cotizado" es lo que suma el total de la cotización (sin renglones quitados, sin lo que no se
 *    cotizó, sin alternativas). "Vendido" es lo de una cotización en estado Vendida: lo que se marcó
 *    como comprado o, si no se marcó nada, todo.
 *  · Quien no ve importes (Quién ve qué) recibe el reporte sin una sola columna de plata.
 *
 * Los de la vida real: el sistema viejo no guardó qué se vendió, así que "vendido" sólo cuenta lo que
 * se marcó como vendido desde que se usa este sistema.
 */
abstract class Reporte
{
    /** Cuánto es "cotizado" de una cotización: el total de sus renglones (ver unirTotales). */
    protected const SQL_COTIZADO = 'coalesce(t.total, 0)';

    /** Lo vendido de una cotización: lo marcado como comprado o, si no se marcó nada, todo. */
    protected const SQL_VENDIDO = "case when c.estado = 'Vendida' then (case when coalesce(m.n, 0) > 0 then coalesce(m.marcado, 0) else coalesce(t.total, 0) end) else 0 end";

    public function __construct(protected readonly User $usuario, protected readonly FiltrosDeReporte $f) {}

    abstract public function clave(): string;

    abstract public function titulo(): string;

    /**
     * @return array{columnas: list<array<string, mixed>>, filas: list<array<string, mixed>>, totales?: ?array<string, mixed>, avisos?: list<string>}
     */
    abstract protected function armar(): array;

    /** @return array<string, mixed> */
    public function generar(): array
    {
        return [
            'reporte' => $this->clave(),
            'titulo' => $this->titulo(),
            // Un reporte sin fecha no se puede comparar con otro: nadie sabe si mira lo de hoy o lo del mes pasado.
            'generado_el' => now()->toIso8601String(),
            'periodo' => ['desde' => $this->f->desde, 'hasta' => $this->f->hasta],
            'permisos' => ['ve_importes' => $this->veImportes()],
        ] + $this->armar() + ['totales' => null, 'avisos' => []];
    }

    /**
     * Para el Excel: los títulos y las filas, con el mismo orden de columnas que en pantalla.
     *
     * @return array{titulos: list<string>, filas: list<list<string|int|float|null>>}
     */
    public function paraExcel(): array
    {
        $r = $this->generar();
        $columnas = $r['columnas'];
        $valor = function (array $columna, array $fila): string|int|float|null {
            $v = $fila[$columna['clave']] ?? null;

            if ($v === null || $v === '') {
                return null;
            }

            return match ($columna['tipo']) {
                'fecha' => date('d/m/Y', (int) strtotime((string) $v)),
                'numero' => (int) $v,
                'importe', 'porcentaje' => (float) $v,
                default => (string) $v,
            };
        };

        $filas = array_map(fn (array $f) => array_map(fn ($c) => $valor($c, $f), $columnas), $r['filas']);

        if (is_array($r['totales'])) {
            $filas[] = array_map(fn ($c) => $valor($c, $r['totales']), $columnas);
        }

        return ['titulos' => array_column($columnas, 'titulo'), 'filas' => $filas];
    }

    public function veImportes(): bool
    {
        return $this->usuario->veImportes();
    }

    /* --------------------------------------------------------------- fechas */

    /** El año de una fecha, en SQL, en cualquiera de las dos bases. */
    protected static function anio(string $columna): string
    {
        return DB::getDriverName() === 'sqlite' ? "strftime('%Y', {$columna})" : "date_format({$columna}, '%Y')";
    }

    /** El mes ("2026-10") de una fecha, en SQL. */
    protected static function mes(string $columna): string
    {
        return DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m', {$columna})" : "date_format({$columna}, '%Y-%m')";
    }

    /* ---------------------------------------------------------- consultas base */

    /**
     * Las cotizaciones firmes (alias `c`) con su empresa (`e`), ya filtradas por lo que se pidió.
     * Con $conFecha en false no se mira la fecha de la cotización (los comprobantes tienen la suya).
     */
    protected function cotizaciones(bool $conFecha = true): Builder
    {
        $q = DB::table('consultas as c')
            ->join('empresas as e', 'e.id', '=', 'c.empresa_id')
            ->whereNull('c.deleted_at')
            ->where('c.tipo', 'Cotizacion')
            ->where('c.estado', '!=', 'Borrador');

        $this->filtrarCotizaciones($q, $conFecha);

        return $q;
    }

    protected function filtrarCotizaciones(Builder $q, bool $conFecha = true): void
    {
        $f = $this->f;

        if ($conFecha) {
            $q->when($f->desde, fn ($q, $v) => $q->whereDate('c.fecha', '>=', $v))
                ->when($f->hasta, fn ($q, $v) => $q->whereDate('c.fecha', '<=', $v));
        }

        $q->when($f->empresaId, fn ($q, $v) => $q->where('c.empresa_id', $v))
            ->when($f->empresa, fn ($q, $v) => $q->where('e.nombre', 'like', "%{$v}%"))
            ->when($f->usuarioId, fn ($q, $v) => $q->where('c.usuario_id', $v))
            ->when($f->monedaId, fn ($q, $v) => $q->where('c.moneda_id', $v));
    }

    /** Le suma a una consulta de cotizaciones lo cotizado (`t.total`) y lo marcado como comprado (`m.*`). */
    protected function unirTotales(Builder $q): Builder
    {
        return $q
            ->leftJoin(DB::raw('(select consulta_id, sum(importe) as total from consulta_lineas where quitada = 0 and no_cotizada = 0 and alternativa_de_id is null group by consulta_id) as t'), 't.consulta_id', '=', 'c.id')
            ->leftJoin(DB::raw('(select consulta_id, sum(importe) as marcado, count(*) as n from consulta_lineas where quitada = 0 and comprada = 1 group by consulta_id) as m'), 'm.consulta_id', '=', 'c.id');
    }

    /* --------------------------------------------------------------- monedas */

    /** @return array<int, string> id => nombre corto ("DOLAR BILLETE", "PESOS"); 0 es "Sin moneda" */
    protected function nombresDeMonedas(): array
    {
        $nombres = Moneda::query()->pluck('nombre', 'id')
            ->map(fn ($n) => implode(' ', array_slice(explode(' ', (string) $n), 0, 2)))->all();

        return $nombres + [0 => 'Sin moneda'];
    }

    /**
     * Las monedas que aparecen, de la que más plata mueve a la que menos.
     *
     * @param  Collection<int, object>  $largas  filas con ->moneda_id
     * @param  list<string>  $campos  los campos de importe que se suman para ordenar
     * @return list<int>
     */
    protected function monedasPorVolumen(Collection $largas, array $campos): array
    {
        return $largas->groupBy(fn ($f) => (int) ($f->moneda_id ?? 0))
            ->map(fn ($filas) => $filas->sum(fn ($f) => array_sum(array_map(fn ($c) => (float) ($f->{$c} ?? 0), $campos))))
            ->sortDesc()->keys()->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Una columna de importe por cada moneda y medida: "Cotizado · DOLAR BILLETE", "Vendido · PESOS".
     *
     * @param  list<int>  $monedas
     * @param  array<string, string>  $medidas  clave => título ("cotizado" => "Cotizado")
     * @return list<array<string, mixed>>
     */
    protected function columnasDeImporte(array $monedas, array $medidas): array
    {
        if (! $this->veImportes()) {
            return [];
        }

        $nombres = $this->nombresDeMonedas();
        $columnas = [];

        foreach ($monedas as $mid) {
            foreach ($medidas as $clave => $titulo) {
                $columnas[] = ['clave' => "{$clave}_{$mid}", 'titulo' => "{$titulo} · ".($nombres[$mid] ?? 'Sin moneda'), 'tipo' => 'importe'];
            }
        }

        return $columnas;
    }

    protected static function porcentaje(float|int $parte, float|int $total): float
    {
        return $total > 0 ? round($parte * 100 / $total, 1) : 0.0;
    }

    /**
     * La fila de totales: suma las columnas de números e importes y recalcula los porcentajes.
     *
     * @param  list<array<string, mixed>>  $columnas
     * @param  list<array<string, mixed>>  $filas
     * @param  array<string, array{0: string, 1: string}>  $porcentajes  columna => [parte, total]
     * @return array<string, mixed>
     */
    protected function totales(array $columnas, array $filas, string $etiqueta, string $columnaDeEtiqueta, array $porcentajes = []): array
    {
        $t = [$columnaDeEtiqueta => $etiqueta];

        foreach ($columnas as $c) {
            if (in_array($c['tipo'], ['numero', 'importe'], true) && ! ($c['sinTotal'] ?? false)) {
                $suma = array_sum(array_map(fn ($f) => (float) ($f[$c['clave']] ?? 0), $filas));
                $t[$c['clave']] = $c['tipo'] === 'numero' ? (int) $suma : round($suma, 2);
            }
        }

        foreach ($porcentajes as $clave => [$parte, $total]) {
            $t[$clave] = self::porcentaje($t[$parte] ?? 0, $t[$total] ?? 0);
        }

        return $t;
    }
}
