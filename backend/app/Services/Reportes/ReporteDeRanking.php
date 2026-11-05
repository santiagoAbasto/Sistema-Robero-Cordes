<?php

namespace App\Services\Reportes;

/**
 * El ranking: las empresas, los materiales o las personas que más cotizan o más venden, ordenados por
 * lo que se pida (cantidad de cotizaciones, de ventas, o plata cotizada o vendida en una moneda).
 *
 * Reusa las mismas cuentas de los otros reportes: un ranking no es otra cuenta, es la misma ordenada
 * y cortada.
 */
final class ReporteDeRanking extends ReporteDeCotizaciones
{
    public function clave(): string
    {
        return 'ranking';
    }

    public function titulo(): string
    {
        return 'Ranking';
    }

    protected function armar(): array
    {
        return $this->f->por === 'materiales' ? $this->deMateriales() : $this->deCotizaciones();
    }

    /** La moneda por la que se ordena cuando la medida es plata: la pedida, o la que más mueve. */
    private function monedaDeOrden(array $monedas): int
    {
        return $this->f->monedaId !== null && in_array($this->f->monedaId, $monedas, true) ? $this->f->monedaId : ($monedas[0] ?? 0);
    }

    /** La medida pedida, o la de cantidad si quien mira no ve importes. */
    private function medida(): string
    {
        $m = $this->f->medida;

        return in_array($m, ['cotizado', 'vendido'], true) && ! $this->veImportes() ? 'cotizaciones' : $m;
    }

    private function deCotizaciones(): array
    {
        $porUsuario = $this->f->por === 'usuarios';

        [$grupos, $monedas] = $porUsuario
            ? $this->agregar('c.usuario_id', 'max(coalesce(u.iniciales, u.name)) as nombre', fn ($q) => $q->leftJoin('users as u', 'u.id', '=', 'c.usuario_id'))
            : $this->agregar('c.empresa_id', 'max(e.nombre) as nombre, max(e.activa) as activa');

        $filas = [];
        foreach ($grupos as $clave => $g) {
            $filas[] = $this->filaBase($g, $monedas) + [
                'clave' => $clave,
                'nombre' => (string) ($g['nombre'] ?? ($porUsuario ? 'Sin usuario' : "Empresa {$clave}")),
                'enlace' => $porUsuario
                    ? '/buscador?'.http_build_query(array_filter(['grano' => 'consultas', 'usuario_id' => $clave, 'desde' => $this->f->desde, 'hasta' => $this->f->hasta]))
                    : "/empresas/{$clave}",
                'apagada' => ! $porUsuario && ! (bool) ($g['activa'] ?? true),
            ];
        }

        $medida = $this->medida();
        $mid = $this->monedaDeOrden($monedas);
        $clave = match ($medida) {
            'vendidas' => 'vendidas',
            'cotizado', 'vendido' => "{$medida}_{$mid}",
            default => 'cotizaciones',
        };

        usort($filas, fn ($a, $b) => [$b[$clave] ?? 0, $b['cotizaciones'], $a['nombre']] <=> [$a[$clave] ?? 0, $a['cotizaciones'], $b['nombre']]);
        $filas = array_slice($filas, 0, $this->f->top);
        foreach ($filas as $i => &$fila) {
            $fila['puesto'] = $i + 1;
        }
        unset($fila);

        $columnas = [
            ['clave' => 'puesto', 'titulo' => '#', 'tipo' => 'numero', 'sinTotal' => true],
            ['clave' => 'nombre', 'titulo' => $porUsuario ? 'Quién la hizo' : 'Empresa', 'tipo' => 'texto'],
            ['clave' => 'cotizaciones', 'titulo' => 'Cotizaciones', 'tipo' => 'numero'],
            ['clave' => 'vendidas', 'titulo' => 'Vendidas', 'tipo' => 'numero'],
            ['clave' => 'vendidas_pct', 'titulo' => '% vendidas', 'tipo' => 'porcentaje'],
            ...$this->columnasDeImporte($monedas, ['cotizado' => 'Cotizado', 'vendido' => 'Vendido']),
        ];

        return [
            'columnas' => $columnas,
            'filas' => $filas,
            'totales' => null,
            'avisos' => $this->avisos($medida, $monedas),
        ];
    }

    private function deMateriales(): array
    {
        $r = (new ReporteDeMateriales($this->usuario, $this->f))->calcular();
        $medida = $this->medida();
        $mid = $this->monedaDeOrden($r['monedas']);
        $clave = match ($medida) {
            'vendidas' => 'vendidos',
            'cotizado', 'vendido' => "{$medida}_{$mid}",
            default => 'renglones',
        };

        // Lo que no tiene material enlazado no es un material: no entra al ranking.
        $filas = collect($r['filas'])->reject(fn ($f) => $f['clave'] === 'sin-enlazar')
            ->sortBy([fn ($a, $b) => [$b[$clave] ?? 0, $b['renglones']] <=> [$a[$clave] ?? 0, $a['renglones']], fn ($a, $b) => strcmp($a['material'], $b['material'])])
            ->values()->take($this->f->top)->map(fn ($f, $i) => ['puesto' => $i + 1] + $f)->all();

        $columnas = [
            ['clave' => 'puesto', 'titulo' => '#', 'tipo' => 'numero', 'sinTotal' => true],
            ...(new ReporteDeMateriales($this->usuario, $this->f))->columnas($r['monedas']),
        ];

        return ['columnas' => $columnas, 'filas' => $filas, 'totales' => null, 'avisos' => $this->avisos($medida, $r['monedas'])];
    }

    /** @param  list<int>  $monedas */
    private function avisos(string $medida, array $monedas): array
    {
        if (! in_array($medida, ['cotizado', 'vendido'], true) || $monedas === []) {
            return [];
        }

        $nombre = $this->nombresDeMonedas()[$this->monedaDeOrden($monedas)] ?? '';

        return ['Ordenado por lo '.($medida === 'cotizado' ? 'cotizado' : 'vendido')." en {$nombre}".($this->f->monedaId ? '.' : ' (la moneda con más movimiento). Las demás monedas se ven en sus columnas; no se suman entre sí.')];
    }
}
