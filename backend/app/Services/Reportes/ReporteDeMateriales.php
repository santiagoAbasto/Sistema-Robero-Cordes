<?php

namespace App\Services\Reportes;

use App\Models\Material;
use Illuminate\Database\Query\Builder;

/**
 * Por material, renglón por renglón: cuánto se cotizó de cada uno, a cuántas empresas, cuánto se
 * vendió y por cuánta plata.
 *
 * Cuenta los renglones principales (las alternativas se ofrecen para elegir una, no suman). Un
 * renglón está vendido cuando su cotización está vendida y se marcó como comprado él o una
 * alternativa suya, o cuando no se marcó nada (compra total).
 *
 * Los renglones que vienen del sistema anterior casi nunca tienen el material enlazado al catálogo
 * (lineas:enlazar reconoce los claros; el resto está escrito en abreviaturas y no se adivina): no se
 * pueden contar por material y van aparte, en una fila al final.
 */
final class ReporteDeMateriales extends Reporte
{
    private const SIN_ENLAZAR = '(material sin reconocer)';

    public function clave(): string
    {
        return 'material';
    }

    public function titulo(): string
    {
        return 'Por material';
    }

    /** Un renglón vendido: su cotización lo está, y lo marcaron (a él o a una alternativa) o no marcaron nada. */
    private static function vendido(): string
    {
        return "(c.estado = 'Vendida' and (l.comprada = 1"
            .' or exists (select 1 from consulta_lineas a where a.alternativa_de_id = l.id and a.quitada = 0 and a.comprada = 1)'
            .' or not exists (select 1 from consulta_lineas k where k.consulta_id = c.id and k.quitada = 0 and k.comprada = 1)))';
    }

    /** Lo que se vendió de un renglón: su importe, o el de la alternativa que compraron en su lugar. */
    private static function importeVendido(): string
    {
        return 'case when '.self::vendido().' then (case'
            .' when l.comprada = 1 then l.importe'
            .' when exists (select 1 from consulta_lineas a where a.alternativa_de_id = l.id and a.quitada = 0 and a.comprada = 1)'
            .' then (select sum(a.importe) from consulta_lineas a where a.alternativa_de_id = l.id and a.quitada = 0 and a.comprada = 1)'
            .' else l.importe end) else 0 end';
    }

    private function renglones(): Builder
    {
        $q = $this->cotizaciones()
            ->join('consulta_lineas as l', 'l.consulta_id', '=', 'c.id')
            ->where('l.quitada', false)->where('l.no_cotizada', false)->whereNull('l.alternativa_de_id');

        $ids = match (true) {
            $this->f->materialId !== null => [$this->f->materialId],
            $this->f->material !== null => Material::buscar($this->f->material)->pluck('id')->all(),
            default => null,
        };

        $ids !== null && $q->whereIn('l.material_id', $ids === [] ? [0] : $ids);
        $this->f->formaId !== null && $q->where('l.forma_id', $this->f->formaId);

        return $q;
    }

    /**
     * @return array{filas: array<string, array<string, mixed>>, monedas: list<int>, sin_enlazar: int}
     */
    public function calcular(): array
    {
        $grupos = $this->renglones()
            ->selectRaw('l.material_id as clave, count(*) as renglones, count(distinct c.id) as cotizaciones, count(distinct c.empresa_id) as empresas, '
                .'sum(case when '.self::vendido().' then 1 else 0 end) as vendidos, max(c.fecha) as ultima')
            ->groupBy('l.material_id')->get();

        $importes = $this->veImportes()
            ? $this->renglones()
                ->selectRaw('l.material_id as clave, c.moneda_id, sum(l.importe) as cotizado, sum('.self::importeVendido().') as vendido')
                ->groupBy('l.material_id', 'c.moneda_id')->get()
            : collect();

        $monedas = $this->monedasPorVolumen($importes, ['cotizado', 'vendido']);
        $nombres = Material::whereIn('id', $grupos->pluck('clave')->filter()->all())->pluck('nombre', 'id');
        $enlace = fn (?int $id) => $id === null ? null : '/buscador?'.http_build_query(array_filter([
            'grano' => 'renglones', 'material_id' => $id, 'desde' => $this->f->desde, 'hasta' => $this->f->hasta,
            'usuario_id' => $this->f->usuarioId, 'empresa_id' => $this->f->empresaId,
        ]));

        $filas = [];
        foreach ($grupos as $g) {
            $id = $g->clave === null ? null : (int) $g->clave;
            $fila = [
                'clave' => $id ?? 'sin-enlazar',
                'material' => $id === null ? self::SIN_ENLAZAR : ($nombres[$id] ?? "Material {$id}"),
                'renglones' => (int) $g->renglones,
                'cotizaciones' => (int) $g->cotizaciones,
                'empresas' => (int) $g->empresas,
                'vendidos' => (int) $g->vendidos,
                'vendidos_pct' => self::porcentaje((int) $g->vendidos, (int) $g->renglones),
                'ultima' => $g->ultima ? substr((string) $g->ultima, 0, 10) : null,
                'enlace' => $enlace($id),
            ];

            foreach ($monedas as $mid) {
                $fila["cotizado_{$mid}"] = 0.0;
                $fila["vendido_{$mid}"] = 0.0;
            }

            $filas[$g->clave === null ? 'sin-enlazar' : (string) $id] = $fila;
        }

        foreach ($importes as $i) {
            $k = $i->clave === null ? 'sin-enlazar' : (string) $i->clave;
            $mid = (int) ($i->moneda_id ?? 0);
            $filas[$k]["cotizado_{$mid}"] = round((float) $i->cotizado, 2);
            $filas[$k]["vendido_{$mid}"] = round((float) $i->vendido, 2);
        }

        return ['filas' => $filas, 'monedas' => $monedas, 'sin_enlazar' => (int) ($filas['sin-enlazar']['renglones'] ?? 0)];
    }

    /** @return list<array<string, mixed>> */
    public function columnas(array $monedas): array
    {
        return [
            ['clave' => 'material', 'titulo' => 'Material', 'tipo' => 'texto'],
            ['clave' => 'renglones', 'titulo' => 'Renglones cotizados', 'tipo' => 'numero'],
            // Una cotización con varios materiales cuenta en cada uno: sumarlas daría de más.
            ['clave' => 'cotizaciones', 'titulo' => 'Cotizaciones', 'tipo' => 'numero', 'sinTotal' => true],
            ['clave' => 'empresas', 'titulo' => 'Empresas', 'tipo' => 'numero', 'sinTotal' => true],
            ['clave' => 'vendidos', 'titulo' => 'Vendidos', 'tipo' => 'numero'],
            ['clave' => 'vendidos_pct', 'titulo' => '% vendido', 'tipo' => 'porcentaje'],
            ['clave' => 'ultima', 'titulo' => 'Última cotización', 'tipo' => 'fecha'],
            ...$this->columnasDeImporte($monedas, ['cotizado' => 'Cotizado', 'vendido' => 'Vendido']),
        ];
    }

    protected function armar(): array
    {
        $r = $this->calcular();
        $columnas = $this->columnas($r['monedas']);

        // De lo que más se cotizó a lo que menos; lo que no tiene material enlazado, siempre al final.
        $filas = collect($r['filas'])->sortBy([
            fn ($a, $b) => ($a['clave'] === 'sin-enlazar') <=> ($b['clave'] === 'sin-enlazar'),
            fn ($a, $b) => $b['renglones'] <=> $a['renglones'],
            fn ($a, $b) => strcmp($a['material'], $b['material']),
        ])->values()->take(2000)->all();

        return [
            'columnas' => $columnas,
            'filas' => $filas,
            'totales' => $this->totales($columnas, $filas, 'Total', 'material', ['vendidos_pct' => ['vendidos', 'renglones']]),
            'avisos' => $r['sin_enlazar'] > 0 ? [
                number_format($r['sin_enlazar'], 0, ',', '.').' renglones no tienen el material reconocido (vienen del sistema anterior, escritos en abreviaturas): '
                .'no se pueden contar por material y figuran juntos al final. En el Buscador se siguen encontrando por lo que dice su descripción.',
            ] : [],
        ];
    }
}
