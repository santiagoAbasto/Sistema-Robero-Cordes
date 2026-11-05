<?php

namespace App\Services\Cordes;

use App\Models\User;
use App\Services\Buscador\Buscador;
use App\Services\Buscador\Filtros;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que dicen los números de una búsqueda, calculado por código (no por la IA).
 *
 * Por qué acá: las cifras que se ven en la respuesta —cuántas son, por dónde se reparten, cuántas hay
 * si se cambia de vista, qué filtro sobra cuando no aparece nada— salen de las mismas consultas que usa el
 * Buscador, así que SIEMPRE coinciden con la lista que se ve debajo. No dependen de lo que escriba la IA.
 *
 * Cada atajo trae el pedido completo ya armado (el de la búsqueda más el cambio): la pantalla sólo lo aplica.
 */
final class Analista
{
    /** Cómo se llaman en castellano los filtros, para decir "sin el período hay 12". */
    private const GRUPOS = [
        'lugar' => [['provincia_id', 'localidad_id'], 'el lugar'],
        'periodo' => [['desde', 'hasta', 'fecha_de'], 'el período'],
        'material' => [['material', 'material_id'], 'el material'],
        'forma' => [['forma_id'], 'la forma'],
        'medida' => [['diametro_desde', 'diametro_hasta'], 'la medida'],
        'estado' => [['estado'], 'el estado'],
        'tipo' => [['tipo'], 'el tipo'],
        'compra' => [['compra', 'orden_de_compra'], 'la compra'],
        'respuesta' => [['sin_respuesta'], '«sin respuesta»'],
        'vence' => [['por_vencer'], '«por vencer»'],
        'moneda' => [['moneda_id'], 'la moneda'],
        'quien' => [['usuario_id'], 'quién la hizo'],
        'empresa' => [['empresa', 'empresa_id'], 'la empresa'],
        'rubro' => [['rubro_id', 'relacion'], 'el rubro'],
        'actividad' => [['sin_cotizar_meses', 'activa'], 'la actividad'],
        'contacto' => [['con_whatsapp', 'con_mail'], 'el contacto'],
        'iva' => [['condicion_iva', 'condicion_pago', 'observacion', 'condicion_id', 'opcion_id'], 'la condición'],
        'comprobante' => [['comprobante', 'tipo_comprobante'], 'el comprobante'],
        'texto' => [['q'], 'el texto'],
    ];

    private const COSA = [
        'empresas' => ['empresa', 'empresas'], 'consultas' => ['cotización', 'cotizaciones'],
        'renglones' => ['renglón de material', 'renglones de material'], 'comprobantes' => ['factura o remito', 'facturas y remitos'],
    ];

    /** Qué se mira de cerca en cada vista, y con qué filtro se afina. */
    private const DESTACAR = [
        'empresas' => ['provincia', 'rubro'],
        'consultas' => ['estado', 'empresa', 'anio'],
        'renglones' => ['material', 'forma', 'empresa'],
        'comprobantes' => ['tipo', 'empresa'],
    ];

    private readonly Buscador $buscador;

    public function __construct(private readonly User $usuario)
    {
        $this->buscador = new Buscador($usuario);
    }

    /**
     * Lo básico y rápido: cuántas hay, cuánto suman y, si no hay nada, qué filtro sobra. Son dos pasadas sobre los datos
     * (con 230.000 renglones, cada una cuenta): sirve para mostrar la respuesta al instante.
     *
     * @param  array<string, string>  $pedido
     * @return array<string, mixed>
     */
    public function basico(array $pedido): array
    {
        return Cache::remember($this->clave('b', $pedido), self::MEMORIA, fn () => $this->calcularBasico($pedido));
    }

    /**
     * Lo completo: además, por dónde se reparte y cuántas hay si se cambia de vista (una decena de pasadas). La pantalla lo pide
     * aparte, cuando ya mostró la respuesta, y lo suma cuando llega.
     *
     * @param  array<string, string>  $pedido
     * @return array<string, mixed>
     */
    public function analizar(array $pedido): array
    {
        return Cache::remember($this->clave('c', $pedido), self::MEMORIA, fn () => $this->calcular($pedido));
    }

    /** Cuánto se acuerda del mismo pedido: con muchos datos se cuenta una vez cada cinco minutos, no cada vez que se pregunta. */
    private const MEMORIA = 300;

    /** Sólo depende de si se ven los importes, no de quién pregunta. */
    private function clave(string $tipo, array $pedido): string
    {
        ksort($pedido);

        return "cordes.analisis.{$tipo}.".md5(json_encode([$pedido, $this->usuario->veImportes()]));
    }

    /** @param  array<string, string>  $pedido
     * @return array<string, mixed> */
    private function calcularBasico(array $pedido): array
    {
        $f = $this->filtros($pedido);
        $total = $this->buscador->contar($f);
        // Sólo las cifras: no se arma una página (lista, orden y cuenta) para quedarse con el resumen.
        $resumen = $total > 0 ? $this->buscador->resumenDe($f) : [];

        return [
            'vista' => $f->grano,
            'total' => $total,
            'titulo' => $total === 0 ? 'No encontré nada con eso' : 'Encontré '.number_format($total, 0, ',', '.').' '.self::COSA[$f->grano][$total === 1 ? 0 : 1],
            'nivel' => match (true) {
                $total === 0 => 'vacio', $total <= 5 => 'pocos', $total > 400 => 'muchos', default => 'normal'
            },
            'cifras' => $this->cifras($f->grano, $resumen),
            'destacados' => [],
            'vistas' => [],
            'relajar' => $total === 0 ? $this->relajar($pedido) : [],
            'completo' => false,
        ];
    }

    /** @param  array<string, string>  $pedido
     * @return array<string, mixed> */
    private function calcular(array $pedido): array
    {
        $f = $this->filtros($pedido);
        $base = $this->basico($pedido);

        return array_merge($base, [
            'destacados' => $base['total'] > 1 ? $this->destacados($f, $pedido, $base['total']) : [],
            'vistas' => $this->vistas($f, $pedido),
            'completo' => true,
        ]);
    }

    /** @param  array<string, string>  $pedido */
    private function filtros(array $pedido, int $porPagina = 1): Filtros
    {
        return Filtros::deArray($pedido + ['por_pagina' => (string) $porPagina]);
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<array{etiqueta: string, valor: string}>
     */
    private function cifras(string $grano, array $r): array
    {
        $c = [];

        if ($grano === 'empresas') {
            ($r['activas'] ?? 0) > 0 && $c[] = ['etiqueta' => 'Activas', 'valor' => (string) $r['activas']];
            ($r['inactivas'] ?? 0) > 0 && $c[] = ['etiqueta' => 'Inactivas', 'valor' => (string) $r['inactivas']];
        }

        foreach (array_slice((array) ($r['por_estado'] ?? []), 0, 4, true) as $estado => $n) {
            $c[] = ['etiqueta' => (string) $estado, 'valor' => (string) $n];
        }

        foreach (array_slice((array) ($r['por_tipo'] ?? []), 0, 3, true) as $tipo => $n) {
            $c[] = ['etiqueta' => (string) $tipo, 'valor' => (string) $n];
        }

        foreach ((array) ($r['importe_por_moneda'] ?? []) as $m) {
            $c[] = ['etiqueta' => ($grano === 'comprobantes' ? 'Facturado' : 'Cotizado').' · '.($m['moneda'] ?? 'Sin moneda'), 'valor' => number_format((float) $m['importe'], 2, ',', '.')];
        }

        return $c;
    }

    /**
     * Por dónde se reparte: cada ítem, un clic para afinar la búsqueda a eso.
     *
     * @param  array<string, string>  $pedido
     * @return list<array<string, mixed>>
     */
    private function destacados(Filtros $f, array $pedido, int $total): array
    {
        $salida = [];

        foreach (self::DESTACAR[$f->grano] as $por) {
            if ($this->yaFiltra($pedido, $por)) {
                continue;
            }

            try {
                $grupos = $this->buscador->agrupar($f, $por, 5);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $items = [];

            foreach ($grupos as $g) {
                $cambio = $this->cambioDe($por, $g);

                if ($cambio === null || $g['n'] === $total && count($grupos) === 1) {
                    continue;
                }

                $items[] = ['etiqueta' => $g['etiqueta'], 'cantidad' => $g['n'], 'porcentaje' => round($g['n'] * 100 / $total), 'pedido' => $cambio + $pedido];
            }

            if (count($items) >= 2 || (count($items) === 1 && $items[0]['cantidad'] < $total)) {
                $salida[] = ['titulo' => ['provincia' => 'Dónde están', 'rubro' => 'De qué rubro', 'estado' => 'En qué estado', 'empresa' => 'Qué empresas', 'anio' => 'Por año',
                    'material' => 'Qué materiales', 'forma' => 'Qué formas', 'tipo' => 'De qué tipo'][$por] ?? ucfirst($por), 'items' => $items];
            }
        }

        return array_slice($salida, 0, 3);
    }

    /** @param  array<string, string>  $pedido */
    private function yaFiltra(array $pedido, string $por): bool
    {
        $claves = match ($por) {
            'provincia' => ['provincia_id', 'localidad_id'], 'rubro' => ['rubro_id'], 'estado' => ['estado', 'sin_respuesta', 'compra'],
            'empresa' => ['empresa_id', 'empresa'], 'anio' => ['desde', 'hasta'], 'material' => ['material_id', 'material'], 'forma' => ['forma_id'],
            'tipo' => ['tipo_comprobante'], default => [],
        };

        return array_intersect_key($pedido, array_flip($claves)) !== [];
    }

    /**
     * @param  array{clave: string, etiqueta: string, n: int}  $g
     * @return array<string, string>|null
     */
    private function cambioDe(string $por, array $g): ?array
    {
        if ($g['clave'] === '') {
            return null;
        }

        return match ($por) {
            'provincia' => ['provincia_id' => $g['clave']], 'rubro' => ['rubro_id' => $g['clave']], 'empresa' => ['empresa_id' => $g['clave']],
            'material' => ['material_id' => $g['clave']], 'forma' => ['forma_id' => $g['clave']], 'estado' => ['estado' => $g['etiqueta']],
            'tipo' => ['tipo_comprobante' => $g['etiqueta']],
            'anio' => preg_match('/^\d{4}$/', $g['clave']) ? ['desde' => $g['clave'].'-01-01', 'hasta' => $g['clave'].'-12-31'] : null,
            default => null,
        };
    }

    /**
     * Lo mismo, mirado desde otra vista, con su cuenta.
     *
     * @param  array<string, string>  $pedido
     * @return list<array<string, mixed>>
     */
    private function vistas(Filtros $f, array $pedido): array
    {
        $salida = [];

        foreach (Filtros::GRANOS as $g) {
            if ($g === $f->grano) {
                continue;
            }

            $otro = ['grano' => $g] + array_diff_key($pedido, ['grano' => 1, 'orden' => 1, 'pagina' => 1]);
            $n = $this->buscador->contar($this->filtros($otro));

            if ($n > 0) {
                $salida[] = ['vista' => $g, 'titulo' => 'Ver '.number_format($n, 0, ',', '.').' '.self::COSA[$g][$n === 1 ? 0 : 1], 'cantidad' => $n,
                    'pedido' => $g === 'empresas' ? array_diff_key($otro, ['grano' => 1]) : $otro];
            }
        }

        return $salida;
    }

    /**
     * Cuando no hay nada: qué filtro sobra. Se prueba sacar cada uno y se cuenta.
     *
     * @param  array<string, string>  $pedido
     * @return list<array<string, mixed>>
     */
    private function relajar(array $pedido): array
    {
        $salida = [];

        foreach (self::GRUPOS as [$claves, $nombre]) {
            if (array_intersect_key($pedido, array_flip($claves)) === []) {
                continue;
            }

            $sin = array_diff_key($pedido, array_flip($claves));
            $n = $this->buscador->contar($this->filtros($sin));

            if ($n > 0) {
                $salida[] = ['etiqueta' => "Sin {$nombre} hay ".number_format($n, 0, ',', '.'), 'cantidad' => $n, 'pedido' => $sin];
            }
        }

        usort($salida, fn ($a, $b) => $b['cantidad'] <=> $a['cantidad']);

        return array_slice($salida, 0, 4);
    }
}
