<?php

namespace App\Services\Reportes;

use App\Services\Buscador\Buscador;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Compras y comprobantes, mes por mes: cuántas ventas se cerraron, cuántas con compra parcial, y las
 * facturas, remitos y notas de crédito que salieron, con lo facturado por moneda.
 *
 * Los comprobantes se ubican en el mes de su fecha; las ventas, en el mes de su factura, o de su pedido
 * y, si no tienen, el de la cotización. "Facturado" suma el importe cargado en cada factura (es opcional
 * en la pantalla: una factura sin importe cuenta como factura pero no suma plata).
 */
final class ReporteDeComprobantes extends Reporte
{
    public function clave(): string
    {
        return 'comprobantes';
    }

    public function titulo(): string
    {
        return 'Compras y comprobantes';
    }

    /** "Factura A" y "Factura de anticipo" son facturas; todo lo que no es factura, remito o nota va a "otros". */
    private static function familia(string $tipo): string
    {
        $t = mb_strtolower($tipo);

        return match (true) {
            str_starts_with($t, 'factura') => 'facturas',
            str_starts_with($t, 'remito') => 'remitos',
            str_starts_with($t, 'nota') => 'notas',
            default => 'otros',
        };
    }

    protected function armar(): array
    {
        $f = $this->f;
        $mesDeLaFactura = self::mes('k.fecha');

        // Comprobantes: por mes, tipo y moneda.
        $comprobantes = DB::table('consulta_comprobantes as k')
            ->join('consultas as c', 'c.id', '=', 'k.consulta_id')
            ->join('empresas as e', 'e.id', '=', 'c.empresa_id')
            ->whereNull('c.deleted_at')->where('c.estado', '!=', 'Borrador')
            ->when($f->desde, fn ($q, $v) => $q->whereDate('k.fecha', '>=', $v))
            ->when($f->hasta, fn ($q, $v) => $q->whereDate('k.fecha', '<=', $v));
        $this->filtrarCotizaciones($comprobantes, conFecha: false);

        $porMes = $comprobantes
            ->selectRaw("{$mesDeLaFactura} as mes, k.tipo, c.moneda_id, count(*) as n, sum(k.importe) as importe")
            ->groupByRaw("{$mesDeLaFactura}, k.tipo, c.moneda_id")->get();

        // Ventas: las cotizaciones vendidas, en el mes de su factura (o de su pedido, o de su cotización).
        $fechaDeLaVenta = 'coalesce(c.fecha_factura, c.fecha_pedido, c.fecha)';
        $mesDeLaVenta = self::mes($fechaDeLaVenta);
        $ventas = $this->cotizaciones(conFecha: false)->where('c.estado', 'Vendida')
            ->when($f->desde, fn ($q, $v) => $q->whereRaw("date({$fechaDeLaVenta}) >= ?", [$v]))
            ->when($f->hasta, fn ($q, $v) => $q->whereRaw("date({$fechaDeLaVenta}) <= ?", [$v]))
            ->selectRaw("{$mesDeLaVenta} as mes, count(*) as ventas, sum(case when ".Buscador::sqlDeCompra('parcial', 'c').' then 1 else 0 end) as parciales')
            ->groupByRaw($mesDeLaVenta)->get();

        $monedas = $this->veImportes()
            ? $this->monedasPorVolumen($porMes->filter(fn ($r) => self::familia((string) $r->tipo) === 'facturas'), ['importe'])
            : [];

        $filas = [];
        $fila = function (?string $mes) use (&$filas, $monedas) {
            return $filas[$mes ?? 'sin-fecha'] ??= [
                'clave' => $mes ?? 'sin-fecha',
                'mes' => $mes === null ? 'Sin fecha' : self::nombreDelMes($mes),
                'ventas' => 0, 'parciales' => 0, 'facturas' => 0, 'remitos' => 0, 'notas' => 0, 'otros' => 0,
                'enlace' => $mes === null ? null : self::enlaceDelMes($mes),
            ] + array_combine(array_map(fn ($m) => "facturado_{$m}", $monedas), array_fill(0, count($monedas), 0.0));
        };

        foreach ($ventas as $v) {
            $fila($v->mes);
            $filas[$v->mes ?? 'sin-fecha']['ventas'] = (int) $v->ventas;
            $filas[$v->mes ?? 'sin-fecha']['parciales'] = (int) $v->parciales;
        }

        foreach ($porMes as $r) {
            $familia = self::familia((string) $r->tipo);
            $fila($r->mes);
            $filas[$r->mes ?? 'sin-fecha'][$familia] += (int) $r->n;

            if ($familia === 'facturas' && $this->veImportes()) {
                $mid = (int) ($r->moneda_id ?? 0);
                $filas[$r->mes ?? 'sin-fecha']["facturado_{$mid}"] = round(($filas[$r->mes ?? 'sin-fecha']["facturado_{$mid}"] ?? 0) + (float) $r->importe, 2);
            }
        }

        // Del mes más nuevo al más viejo; lo que no tiene fecha, al final.
        $filas = collect($filas)->sortBy([
            fn ($a, $b) => ($a['clave'] === 'sin-fecha') <=> ($b['clave'] === 'sin-fecha'),
            fn ($a, $b) => strcmp((string) $b['clave'], (string) $a['clave']),
        ])->values()->all();

        $columnas = [
            ['clave' => 'mes', 'titulo' => 'Mes', 'tipo' => 'texto'],
            ['clave' => 'ventas', 'titulo' => 'Ventas', 'tipo' => 'numero'],
            ['clave' => 'parciales', 'titulo' => 'Con compra parcial', 'tipo' => 'numero'],
            ['clave' => 'facturas', 'titulo' => 'Facturas', 'tipo' => 'numero'],
            ['clave' => 'remitos', 'titulo' => 'Remitos', 'tipo' => 'numero'],
            ['clave' => 'notas', 'titulo' => 'Notas de crédito y débito', 'tipo' => 'numero'],
            ['clave' => 'otros', 'titulo' => 'Otros', 'tipo' => 'numero'],
            ...$this->columnasDeImporte($monedas, ['facturado' => 'Facturado']),
        ];

        return [
            'columnas' => $columnas,
            'filas' => $filas,
            'totales' => $this->totales($columnas, $filas, 'Total', 'mes'),
            'avisos' => ['Lo facturado suma el importe cargado en cada factura; una factura sin importe se cuenta pero no suma plata.'],
        ];
    }

    private static function nombreDelMes(string $mes): string
    {
        return ucfirst(CarbonImmutable::createFromFormat('!Y-m', $mes)->locale('es')->isoFormat('MMMM YYYY'));
    }

    private static function enlaceDelMes(string $mes): string
    {
        $primero = CarbonImmutable::createFromFormat('!Y-m', $mes);

        return '/buscador?'.http_build_query(['grano' => 'comprobantes', 'desde' => $primero->toDateString(), 'hasta' => $primero->endOfMonth()->toDateString()]);
    }
}
