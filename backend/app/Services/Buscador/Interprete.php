<?php

namespace App\Services\Buscador;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Cordes IA: de una pregunta escrita con las palabras de cada uno, a una búsqueda.
 *
 * LAS REGLAS PRIMERO, LA IA DESPUÉS. Las reglas entienden lo que se pregunta seguido, al
 * instante y sin costo. Sólo si quedan palabras que no entendieron, y hay una IA configurada,
 * se le pregunta a ella; y si falla, se sigue con lo que entendieron las reglas. La IA es una
 * ayuda, no un requisito (igual que en el lector de pedidos).
 *
 * Nunca ejecuta nada: devuelve el pedido para que la pantalla lo muestre, lo explique y lo
 * pueda corregir.
 */
final class Interprete
{
    public function __construct(private readonly ?CarbonImmutable $ahora = null) {}

    /**
     * @return array{pedido: array<string, string>, fuente: string, mensaje: ?string, no_entendi: list<string>, aviso: ?string}
     */
    public function interpretar(string $pregunta, bool $conIA = true): array
    {
        $pregunta = trim(preg_replace('/\s+/', ' ', mb_substr($pregunta, 0, 400)) ?? '');
        $catalogo = new Catalogo($this->ahora);
        $resolver = new ResolverDePedido($catalogo);

        $reglas = (new InterpretePorReglas($catalogo))->interpretar($pregunta);
        $deReglas = $resolver->resolver($reglas['borrador']);
        $palabras = count(array_filter(explode(' ', $pregunta)));

        $alcanzan = $reglas['entendio'] && $reglas['restos'] === [];

        if ($alcanzan || $palabras <= 2 || ! $conIA || ! InterpretePorIA::estaConfigurada()) {
            return $this->conReglas($pregunta, $reglas, $deReglas, null);
        }

        try {
            $borrador = (new InterpretePorIA($catalogo))->preguntar($pregunta);
            $deLaIA = $resolver->resolver($borrador);

            // Una IA que no entendió nada no pisa lo que sí entendieron las reglas.
            if ($deLaIA['pedido'] === [] || array_keys($deLaIA['pedido']) === ['grano']) {
                return $this->conReglas($pregunta, $reglas, $deReglas, 'La IA no encontró qué buscar: se usó lo que se entendió de la frase.');
            }

            $pedido = $deLaIA['pedido'];
            $noEntendi = $deLaIA['no_entendi'];
            $mensaje = $borrador['mensaje'] ?? null;
            $mensaje = is_string($mensaje) && trim($mensaje) !== '' ? mb_substr(trim(strip_tags($mensaje)), 0, 240) : null;

            // Lo que la IA dejó pasar y las reglas reconocen con nombres exactos del catálogo, se completa.
            $completado = $this->completarConLoSeguroDeLasReglas($pedido, $deReglas['pedido']);

            if ($completado !== []) {
                $pedido = [...$pedido, ...$completado];
                // Si la IA decía que no podía con eso, ya no es cierto; y su frase tampoco.
                $noEntendi = array_values(array_filter($noEntendi, fn ($f) => ! $this->hablaDe($f, $reglas['borrador'], array_keys($completado))));
                $mensaje = null;
            }

            return [
                'pedido' => $pedido,
                'fuente' => 'ia',
                'mensaje' => $mensaje,
                'no_entendi' => $noEntendi,
                'aviso' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Cordes IA no pudo usar la IA: '.$e->getMessage());

            return $this->conReglas($pregunta, $reglas, $deReglas, $e->getMessage().': se usó el modo básico.');
        }
    }

    /**
     * Los filtros que las reglas reconocen sin margen de duda: un lugar nombrado tal cual está en el
     * catálogo, "sin respuesta", "hace más de N meses que no", un número de orden de compra o de comprobante.
     * Una IA a veces los pasa por alto; completarlos no puede equivocarse.
     */
    private const SEGUROS = [
        'provincia_id', 'localidad_id', 'sin_cotizar_meses', 'orden_de_compra', 'comprobante',
        'por_vencer', 'compra', 'sin_respuesta', 'con_whatsapp', 'con_mail',
    ];

    /**
     * @param  array<string, string>  $deLaIA
     * @param  array<string, string>  $deReglas
     * @return array<string, string>
     */
    private function completarConLoSeguroDeLasReglas(array $deLaIA, array $deReglas): array
    {
        $completa = array_diff_key(array_intersect_key($deReglas, array_flip(self::SEGUROS)), $deLaIA);

        // Las fechas, sólo si la IA no dijo ninguna: no se le corrige una cuenta, se le llena un hueco.
        if (! isset($deLaIA['desde']) && ! isset($deLaIA['hasta'])) {
            $completa += array_intersect_key($deReglas, array_flip(['desde', 'hasta']));
        }

        return $completa;
    }

    /**
     * ¿La frase que la IA no entendió habla de algo que las reglas sí resolvieron?
     *
     * @param  array<string, mixed>  $borradorDeReglas
     * @param  list<string>  $claves  lo que se completó
     */
    private function hablaDe(string $fragmento, array $borradorDeReglas, array $claves): bool
    {
        $f = Texto::normalizar($fragmento);
        $nombres = [];

        foreach ([['provincia_id', 'provincia'], ['localidad_id', 'localidad']] as [$clave, $campo]) {
            if (in_array($clave, $claves, true) && is_string($borradorDeReglas[$campo] ?? null)) {
                $nombres[] = $borradorDeReglas[$campo];
            }
        }

        foreach ($nombres as $nombre) {
            if ($f !== '' && str_contains($f, $nombre)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{borrador: array<string, mixed>, restos: list<string>, entendio: bool}  $reglas
     * @param  array{pedido: array<string, string>, no_entendi: list<string>}  $deReglas
     * @return array{pedido: array<string, string>, fuente: string, mensaje: ?string, no_entendi: list<string>, aviso: ?string}
     */
    private function conReglas(string $pregunta, array $reglas, array $deReglas, ?string $aviso): array
    {
        $pedido = $deReglas['pedido'];
        $noEntendi = [...$deReglas['no_entendi'], ...$reglas['restos']];
        $mensaje = null;

        // Si no se entendió nada como pregunta, se busca como texto: es lo que haría el buscador de siempre.
        if ($pedido === [] || array_keys($pedido) === ['grano']) {
            $pedido = ['q' => mb_substr($pregunta, 0, 200)] + $pedido;
            $noEntendi = [];
            $mensaje = 'No lo entendí como pregunta, así que lo busqué como texto.';
        }

        return [
            'pedido' => $pedido,
            'fuente' => 'reglas',
            'mensaje' => $mensaje,
            'no_entendi' => array_values(array_unique($noEntendi)),
            'aviso' => $aviso,
        ];
    }
}
