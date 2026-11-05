<?php

namespace App\Services\Cordes;

use App\Models\Consulta;
use App\Models\Empresa;
use App\Services\Buscador\Catalogo;
use App\Services\Buscador\Filtros;
use App\Services\Buscador\ResolverDePedido;

/**
 * La barrera entre lo que dice la IA y lo que ve la persona.
 *
 * La IA propone; acá se comprueba y se arma lo que se muestra. Nada pasa sin mirar:
 *
 *  · El texto no lleva HTML y tiene un largo máximo.
 *  · Los filtros pasan por ResolverDePedido (el único camino a la base): uno inventado se descarta y se avisa.
 *  · Las tablas son SOLO las que devolvió una herramienta (por referencia): la IA no puede escribir una.
 *  · Los atajos se limitan a una lista de acciones y a ids que EXISTEN; las rutas las arma el código, no la IA.
 *  · Si el Verificador no pudo comprobar el texto, no se muestra: se muestran los datos tal cual salieron.
 */
final class Respuesta
{
    public const MAX_ATAJOS = 8;

    private readonly ResolverDePedido $resolver;

    public function __construct(private readonly Catalogo $catalogo = new Catalogo)
    {
        $this->resolver = new ResolverDePedido($this->catalogo);
    }

    /**
     * @param  array{modelo: array<string, mixed>, tablas: array<string, array<string, mixed>>, verificacion: array{ok: bool, sin_comprobar: list<string>}, pasos: list<string>, ultimas_tablas: list<string>}  $a  lo que devolvió el Agente
     * @return array<string, mixed>
     */
    public function desdeLaIA(array $a): array
    {
        $m = $a['modelo'];
        $ok = $a['verificacion']['ok'];

        // Lo que se muestra abajo es lo que REALMENTE se consultó (ya resuelto y validado), no lo que diga la IA: así no
        // puede arrastrar los filtros de otra búsqueda ni mostrar algo distinto de lo que contó.
        // (aunque no se haya podido comprobar el texto: la búsqueda no es una afirmación de la IA, la ejecutó el sistema)
        $pedido = $a['pedido_de_herramientas'] ?? null;

        // Las tablas que pidió, y sólo las que existen.
        $refs = array_values(array_unique(array_filter((array) ($m['tablas'] ?? []), 'is_string')));
        $tablas = [];

        foreach ($ok ? $refs : $a['ultimas_tablas'] as $ref) {
            if (isset($a['tablas'][$ref]) && count($tablas) < 3) {
                $tablas[] = $this->tabla($a['tablas'][$ref]);
            }
        }

        // Aunque no las haya pedido, una consulta de datos sin texto de IA muestra su tabla.
        if ($tablas === [] && ! $ok) {
            foreach (array_slice($a['ultimas_tablas'], 0, 2) as $ref) {
                isset($a['tablas'][$ref]) && $tablas[] = $this->tabla($a['tablas'][$ref]);
            }
        }

        $mensaje = $ok
            ? $this->texto((string) ($m['mensaje'] ?? ''))
            : 'No pude comprobar todas las cifras de mi respuesta contra los datos del sistema, así que no te la muestro. Te dejo los datos tal cual salieron'.($tablas === [] ? '; si querés, probá preguntarlo de otra forma.' : ':');

        $opciones = $ok ? $this->opciones($m['opciones'] ?? null) : null;

        if ($ok && $mensaje === '' && $tablas === [] && $pedido === null && $opciones === null) {
            $mensaje = 'No encontré cómo ayudarte con eso. Probá de otra forma, o elegí una de estas ideas.';
        }

        return [
            'fuente' => 'ia',
            'mensaje' => $mensaje !== '' ? $mensaje : null,
            'verificada' => $ok,
            'aviso' => null,
            'pedido' => $pedido,
            'borrador' => null,
            'tablas' => $tablas,
            'atajos' => $ok ? $this->atajos((array) ($m['atajos'] ?? []), $pedido) : [],
            'opciones' => $opciones,
            'seguir' => $ok ? $this->seguir((array) ($m['seguir'] ?? [])) : [],
            'no_entendi' => array_values(array_unique([...array_map(fn ($x) => mb_substr((string) $x, 0, 80), array_filter((array) ($m['no_entendi'] ?? []), 'is_scalar')), ...($a['no_entendi_de_herramientas'] ?? [])])),
            'pasos' => $a['pasos'],
            'analisis' => null,
        ];
    }

    /** @return array{titulo: string, columnas: list<string>, filas: list<list<string>>, acciones: list<list<array<string, mixed>>>} */
    public function tabla(array $t): array
    {
        $filas = array_slice((array) ($t['filas'] ?? []), 0, 40);

        return [
            'titulo' => mb_substr((string) ($t['titulo'] ?? ''), 0, 80),
            'columnas' => array_map(fn ($c) => mb_substr((string) $c, 0, 40), array_slice((array) ($t['columnas'] ?? []), 0, 10)),
            'filas' => array_map(fn ($f) => array_map(fn ($c) => is_scalar($c) || $c === null ? mb_substr((string) ($c ?? '—'), 0, 140) : '—', array_slice((array) $f, 0, 10)), $filas),
            // Un botón por cosa que se puede abrir: la ficha, la cotización, cotizar, o "ver sólo estas".
            'acciones' => array_map(fn ($i) => $this->accionesDeFila((array) (($t['acciones'] ?? [])[$i] ?? [])), array_keys($filas)),
        ];
    }

    /**
     * Cada acción de una fila la pidió una herramienta con un id propio; la ruta la arma el código.
     *
     * @param  list<array<string, mixed>>  $specs
     * @return list<array<string, mixed>>
     */
    public function accionesDeFila(array $specs): array
    {
        $salida = [];

        foreach (array_slice($specs, 0, 3) as $a) {
            // Las tablas que arma el motor ya traen sus botones armados: se vuelven a validar igual que cualquier otra cosa.
            if (isset($a['accion'])) {
                ($armado = $this->accionYaArmada($a)) && $salida[] = $armado;

                continue;
            }

            $id = $this->id($a['id'] ?? null);
            $armado = match ($a['tipo'] ?? '') {
                'empresa' => $id ? ['etiqueta' => 'Abrir ficha', 'icono' => 'empresa', 'accion' => ['tipo' => 'ruta', 'ruta' => "/empresas/{$id}"]] : null,
                'cotizacion' => $id ? ['etiqueta' => 'Abrir cotización', 'icono' => 'documento', 'accion' => ['tipo' => 'ruta', 'ruta' => "/consultas/{$id}"]] : null,
                'cotizar' => $id ? ['etiqueta' => 'Cotizar', 'icono' => 'mas', 'accion' => ['tipo' => 'ruta', 'ruta' => "/empresas/{$id}/agregar"]] : null,
                'pedido' => is_array($a['pedido'] ?? null) && ($p = Filtros::sanear($a['pedido'])) !== []
                    ? ['etiqueta' => 'Ver estas', 'icono' => 'lista', 'accion' => ['tipo' => 'pedido', 'pedido' => $p]] : null,
                default => null,
            };

            $armado && $salida[] = $armado;
        }

        return $salida;
    }

    /**
     * Un botón que ya viene armado (de una tabla del motor): sólo pasa si la ruta es de una ficha, una cotización o un reporte del sistema,
     * o si el pedido es uno válido del Buscador.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null
     */
    private function accionYaArmada(array $a): ?array
    {
        $accion = is_array($a['accion']) ? $a['accion'] : [];
        $etiqueta = mb_substr(trim(strip_tags((string) ($a['etiqueta'] ?? ''))), 0, 40);
        $icono = in_array($a['icono'] ?? null, ['lista', 'empresa', 'mas', 'documento', 'grafico', 'excel', 'pregunta'], true) ? $a['icono'] : 'lista';

        if ($etiqueta === '') {
            return null;
        }

        $armada = match ($accion['tipo'] ?? '') {
            'ruta' => preg_match('#^/(?:empresas/\d{1,10}(?:/agregar)?|consultas/\d{1,10})$#', (string) ($accion['ruta'] ?? '')) === 1
                ? ['tipo' => 'ruta', 'ruta' => (string) $accion['ruta']] : null,
            'pedido' => ($p = Filtros::sanear((array) ($accion['pedido'] ?? []))) !== [] ? ['tipo' => 'pedido', 'pedido' => $p] : null,
            default => null,
        };

        return $armada === null ? null : ['etiqueta' => $etiqueta, 'icono' => $icono, 'accion' => $armada];
    }

    /** Texto sin HTML ni cosas raras. Se conservan **negrita**, los saltos de línea y las listas con «- ». */
    public function texto(string $t, int $max = 1600): string
    {
        $t = strip_tags($t);
        $t = preg_replace('/[^\P{C}\n]+/u', '', $t) ?? '';
        $t = preg_replace("/[ \t]+/", ' ', $t) ?? '';
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? '';

        return mb_substr(trim($t), 0, $max);
    }

    /**
     * @param  array<int, mixed>  $atajos
     * @param  array<string, string>|null  $pedidoActual
     * @return list<array<string, mixed>>
     */
    private function atajos(array $atajos, ?array $pedidoActual): array
    {
        $salida = [];

        foreach ($atajos as $a) {
            if (! is_array($a) || count($salida) >= self::MAX_ATAJOS) {
                continue;
            }

            $etiqueta = mb_substr(trim(strip_tags((string) ($a['etiqueta'] ?? ''))), 0, 70);

            if ($etiqueta === '') {
                continue;
            }

            $armado = match ($a['tipo'] ?? '') {
                'ver' => $this->atajoVer($a),
                'excel' => $this->atajoExcel($a, $pedidoActual),
                'empresa' => ($id = $this->id($a['id'] ?? null)) && Empresa::query()->whereKey($id)->exists()
                    ? ['icono' => 'empresa', 'accion' => ['tipo' => 'ruta', 'ruta' => "/empresas/{$id}"]] : null,
                'nueva_cotizacion' => ($id = $this->id($a['empresa_id'] ?? null)) && Empresa::query()->whereKey($id)->where('activa', true)->exists()
                    ? ['icono' => 'mas', 'accion' => ['tipo' => 'ruta', 'ruta' => "/empresas/{$id}/agregar"]] : null,
                'cotizacion' => ($id = $this->id($a['id'] ?? null)) && Consulta::query()->whereKey($id)->exists()
                    ? ['icono' => 'documento', 'accion' => ['tipo' => 'ruta', 'ruta' => "/consultas/{$id}"]] : null,
                'reporte' => $this->atajoReporte((string) ($a['reporte'] ?? '')),
                default => null,
            };

            if ($armado !== null) {
                $salida[] = ['etiqueta' => $etiqueta] + $armado;
            }
        }

        return $salida;
    }

    /** @return array<string, mixed>|null */
    private function atajoVer(array $a): ?array
    {
        $vista = is_string($a['vista'] ?? null) && in_array($a['vista'], Filtros::GRANOS, true) ? $a['vista'] : null;
        $r = $this->resolver->resolver(($vista ? ['vista' => $vista] : []) + (is_array($a['filtros'] ?? null) ? $a['filtros'] : []));

        return $r['pedido'] === [] ? null : ['icono' => 'lista', 'accion' => ['tipo' => 'pedido', 'pedido' => $r['pedido']]];
    }

    /**
     * @param  array<string, string>|null  $actual
     * @return array<string, mixed>|null
     */
    private function atajoExcel(array $a, ?array $actual): ?array
    {
        $pedido = is_array($a['filtros'] ?? null) || isset($a['vista'])
            ? ($this->atajoVer($a)['accion']['pedido'] ?? null)
            : $actual;

        return $pedido ? ['icono' => 'excel', 'accion' => ['tipo' => 'excel', 'pedido' => $pedido]] : null;
    }

    /** @return array<string, mixed>|null */
    private function atajoReporte(string $reporte): ?array
    {
        $ruta = match ($reporte) {
            'material', 'empresa', 'comprobantes', 'ranking', 'seguimiento' => "/reportes?r={$reporte}",
            'vencimientos' => '/reportes?r=ranking&sub=vencimientos',
            default => null,
        };

        return $ruta ? ['icono' => 'grafico', 'accion' => ['tipo' => 'ruta', 'ruta' => $ruta]] : null;
    }

    /**
     * Los botones que ya vienen armados (los del código: motor, descompositor): se vuelven a validar igual que los de la IA.
     * Una ruta sólo si es de una ficha, una cotización, un reporte o formas y fórmulas; un pedido sólo si el Buscador lo entiende.
     *
     * @param  array<int, mixed>  $atajos
     * @return list<array<string, mixed>>
     */
    public function atajosArmados(array $atajos, int $max = self::MAX_ATAJOS): array
    {
        $salida = [];

        foreach ($atajos as $a) {
            if (! is_array($a) || count($salida) >= $max) {
                continue;
            }

            $etiqueta = mb_substr(trim(strip_tags((string) ($a['etiqueta'] ?? ''))), 0, 70);
            $accion = is_array($a['accion'] ?? null) ? $a['accion'] : [];
            $armada = match ($accion['tipo'] ?? '') {
                'ruta' => preg_match('#^/(?:empresas/\d{1,10}(?:/agregar)?|consultas/\d{1,10}|reportes(?:\?r=[a-z]+(?:&sub=[a-z]+)?)?|config/formas)$#', (string) ($accion['ruta'] ?? '')) === 1
                    ? ['tipo' => 'ruta', 'ruta' => (string) $accion['ruta']] : null,
                'pedido', 'excel' => ($p = Filtros::sanear((array) ($accion['pedido'] ?? []))) !== [] ? ['tipo' => $accion['tipo'], 'pedido' => $p] : null,
                'preguntar' => ($t = mb_substr(trim(strip_tags((string) ($accion['texto'] ?? ''))), 0, 300)) !== '' ? ['tipo' => 'preguntar', 'texto' => $t] : null,
                'bajar' => ['tipo' => 'bajar'],
                default => null,
            };

            if ($etiqueta !== '' && $armada !== null) {
                $icono = in_array($a['icono'] ?? null, ['lista', 'empresa', 'mas', 'documento', 'grafico', 'excel', 'pregunta'], true) ? $a['icono'] : 'lista';
                $salida[] = ['etiqueta' => $etiqueta, 'icono' => $icono, 'accion' => $armada];
            }
        }

        return $salida;
    }

    /** @return array{pregunta: string, opciones: list<array{etiqueta: string, enviar: string}>}|null */
    public function opcionesValidas(mixed $o): ?array
    {
        // Las del código pueden ser una sola (un único material parecido); las de la IA piden al menos dos.
        return $this->opciones($o, 1);
    }

    /** @return list<string> */
    public function seguirValidos(mixed $s): array
    {
        return $this->seguir(is_array($s) ? $s : []);
    }

    private function id(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v > 0 && (int) $v < 2_000_000_000 ? (int) $v : null;
    }

    /** @return array{pregunta: string, opciones: list<array{etiqueta: string, enviar: string}>}|null */
    private function opciones(mixed $o, int $minimo = 2): ?array
    {
        if (! is_array($o) || ! is_array($o['opciones'] ?? null)) {
            return null;
        }

        $lista = [];

        foreach (array_slice($o['opciones'], 0, 6) as $x) {
            if (is_array($x) && filled($x['etiqueta'] ?? null)) {
                $etiqueta = mb_substr(trim(strip_tags((string) $x['etiqueta'])), 0, 90);
                $lista[] = ['etiqueta' => $etiqueta, 'enviar' => mb_substr(trim(strip_tags((string) ($x['enviar'] ?? $etiqueta))), 0, 200)];
            }
        }

        return count($lista) >= $minimo ? ['pregunta' => mb_substr(trim(strip_tags((string) ($o['pregunta'] ?? '¿Cuál de estas?'))), 0, 160), 'opciones' => $lista] : null;
    }

    /** @return list<string> */
    private function seguir(array $s): array
    {
        return array_slice(array_values(array_filter(array_map(fn ($x) => is_string($x) ? mb_substr(trim(strip_tags($x)), 0, 110) : '', $s))), 0, 3);
    }
}
