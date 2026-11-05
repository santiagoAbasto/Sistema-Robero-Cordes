<?php

namespace App\Services\Buscador;

use App\Models\Consulta;
use App\Models\Forma;
use App\Models\Localidad;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Moneda;
use App\Models\Provincia;
use App\Models\Rubro;
use App\Models\TipoComprobante;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Las listas del sistema, para reconocer en una frase lo que se nombra: una
 * provincia, un rubro, un material, quién hizo la cotización.
 *
 * Se leen de la base una sola vez por pedido y sólo las que hacen falta. Todo
 * se compara sin tildes ni mayúsculas (ver Texto::normalizar).
 */
final class Catalogo
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly ?CarbonImmutable $ahora = null) {}

    public function hoy(): CarbonImmutable
    {
        return ($this->ahora ?? CarbonImmutable::now(config('app.timezone')))->startOfDay();
    }

    /* --------------------------------------------------------------- listas */

    /** @return array<int, string> id => nombre normalizado */
    private function nombres(string $clave, callable $cargar): array
    {
        /** @var array<int, string> */
        return $this->memo[$clave] ??= collect($cargar())->map(fn ($n) => Texto::normalizar((string) $n))->all();
    }

    public function provincia(string $texto): ?int
    {
        return $this->mejor($this->nombres('provincias', fn () => Provincia::where('activo', true)->pluck('nombre', 'id')), $texto);
    }

    public function localidad(string $texto): ?int
    {
        return $this->mejor($this->nombres('localidades', fn () => Localidad::where('activo', true)->pluck('nombre', 'id')), $texto);
    }

    public function rubro(string $texto): ?int
    {
        return $this->mejor($this->nombres('rubros', fn () => Rubro::where('activo', true)->pluck('nombre', 'id')), $texto);
    }

    /** @return array<int, string> las provincias que se nombran en una frase ya normalizada */
    public function provinciasEn(string $norm): array
    {
        return $this->nombradasEn($this->nombres('provincias', fn () => Provincia::where('activo', true)->pluck('nombre', 'id')), $norm);
    }

    /** @return array<int, string> las localidades que se nombran en una frase ya normalizada */
    public function localidadesEn(string $norm): array
    {
        return $this->nombradasEn($this->nombres('localidades', fn () => Localidad::where('activo', true)->pluck('nombre', 'id')), $norm);
    }

    /** Quién hizo la cotización: por sus iniciales ("RIC") o por su nombre de pila. */
    public function usuario(string $texto): ?int
    {
        $buscado = Texto::normalizar($texto);

        if ($buscado === '') {
            return null;
        }

        /** @var array<int, array{iniciales: string, palabras: list<string>}> $usuarios */
        $usuarios = $this->memo['usuarios'] ??= User::where('activo', true)->get(['id', 'name', 'iniciales'])
            ->mapWithKeys(fn (User $u) => [$u->id => [
                'iniciales' => Texto::normalizar((string) $u->iniciales),
                'palabras' => explode(' ', Texto::normalizar($u->name)),
            ]])->all();

        foreach ($usuarios as $id => $u) {
            if ($u['iniciales'] !== '' && $u['iniciales'] === $buscado) {
                return $id;
            }
        }

        // Por nombre de pila, sólo si no hay dudas de quién es.
        $coinciden = array_keys(array_filter($usuarios, fn ($u) => in_array($buscado, $u['palabras'], true)));

        return count($coinciden) === 1 ? $coinciden[0] : null;
    }

    /** "dólares", "pesos", "euros": de las monedas que hay, la más usual de esa clase. */
    public function moneda(string $texto): ?int
    {
        $buscado = Texto::normalizar($texto);
        $clase = match (true) {
            (bool) preg_match('/dolar|usd|\bu s(?: d)?\b/', $buscado) => 'dolar',
            str_contains($buscado, 'peso') || $buscado === 'ars' => 'peso',
            str_contains($buscado, 'euro') || $buscado === 'eur' => 'euro',
            default => null,
        };

        if ($clase === null) {
            return null;
        }

        /** @var list<array{id: int, nombre: string, por_defecto: bool}> $monedas */
        $monedas = $this->memo['monedas'] ??= Moneda::where('activo', true)->orderBy('id')->get(['id', 'nombre', 'por_defecto'])
            ->map(fn (Moneda $m) => ['id' => $m->id, 'nombre' => Texto::normalizar($m->nombre), 'por_defecto' => (bool) $m->por_defecto])
            ->all();

        $de_la_clase = array_values(array_filter($monedas, fn ($m) => str_contains($m['nombre'], $clase)));

        // La de siempre si es de esa clase; si no, la "billete" (la que usan para facturar); si no, la primera.
        foreach (['por_defecto' => fn ($m) => $m['por_defecto'], 'billete' => fn ($m) => str_contains($m['nombre'], 'billete')] as $criterio) {
            foreach ($de_la_clase as $m) {
                if ($criterio($m)) {
                    return $m['id'];
                }
            }
        }

        return $de_la_clase[0]['id'] ?? null;
    }

    /** @return array<int, list<string>> id => las formas de escribir esa forma ("anillos / aros" son dos), ya normalizadas */
    private function formas(): array
    {
        /** @var array<int, list<string>> */
        return $this->memo['formas'] ??= Forma::where('activo', true)->pluck('nombre', 'id')
            ->map(fn ($n) => array_values(array_filter(array_map(Texto::normalizar(...), explode('/', (string) $n)))))
            ->all();
    }

    /**
     * "barra", "barras", "caños", "chapa": la forma que se nombra. Sin más datos,
     * "barra" es barra redonda: así lo resolvió CORDES (BarraEsBarraRedondaTest).
     */
    public function forma(string $texto): ?int
    {
        $buscado = Texto::normalizar($texto);
        $largo = $buscado === '' ? 0 : count(explode(' ', $buscado));

        if ($largo === 0) {
            return null;
        }

        $candidatas = [];

        foreach ($this->formas() as $id => $variantes) {
            foreach ($variantes as $variante) {
                if (Texto::mismaFrase($variante, $buscado)) {
                    return $id;
                }

                $palabras = explode(' ', $variante);

                if (count($palabras) > $largo && Texto::mismaFrase(implode(' ', array_slice($palabras, 0, $largo)), $buscado)) {
                    $candidatas[$id] = $variante;
                }
            }
        }

        // "barra" a secas, y hay varias barras: la redonda.
        foreach ($candidatas as $id => $variante) {
            if (Texto::mismaFrase($variante, $buscado.' redonda')) {
                return $id;
            }
        }

        $unica = count($candidatas) === 1 ? array_key_first($candidatas) : null;

        // Cómo le dice la gente a las formas que el catálogo nombra de otra manera (sólo si no hay una forma con ese nombre).
        if ($unica === null && isset(self::SINONIMOS_DE_FORMA[$buscado])) {
            return $this->forma(self::SINONIMOS_DE_FORMA[$buscado]);
        }

        // «La cuadrada», «una hexagonal»: sólo el adjetivo; si lo lleva una sola forma («BARRA CUADRADA»), es esa.
        if ($unica === null && $largo === 1 && mb_strlen($buscado) >= 5) {
            $conLaPalabra = [];

            foreach ($this->formas() as $id => $variantes) {
                foreach ($variantes as $variante) {
                    $palabras = explode(' ', $variante);

                    if (count($palabras) > 1 && in_array($buscado, array_slice($palabras, 1), true)) {
                        $conLaPalabra[$id] = true;
                    }
                }
            }

            return count($conLaPalabra) === 1 ? array_key_first($conLaPalabra) : null;
        }

        return $unica;
    }

    /** @var array<string, string> cómo se dice → cómo se llama la forma en el catálogo */
    private const SINONIMOS_DE_FORMA = [
        'platina' => 'planchuela', 'platinas' => 'planchuela', 'pletina' => 'planchuela', 'pletinas' => 'planchuela',
        'plancha' => 'chapa', 'planchas' => 'chapa', 'lamina' => 'chapa', 'laminas' => 'chapa',
        'tuberia' => 'cano', 'tuberias' => 'cano', 'caneria' => 'cano', 'canerias' => 'cano',
    ];

    /** El tipo de comprobante, tal como figura en la lista ("Remito", "Nota de crédito"). */
    public function tipoDeComprobante(string $texto): ?string
    {
        $buscado = Texto::normalizar($texto);

        /** @var array<string, string> $tipos normalizado => nombre */
        $tipos = $this->memo['tipos_de_comprobante'] ??= TipoComprobante::where('activo', true)->pluck('nombre')
            ->mapWithKeys(fn ($n) => [Texto::normalizar($n) => $n])->all();

        foreach ($tipos as $normalizado => $nombre) {
            if ($buscado !== '' && Texto::mismaFrase($normalizado, $buscado)) {
                return $nombre;
            }
        }

        return null;
    }

    /** Un estado de la lista de estados, por cómo lo dice la gente: "vendidas", "borradores", "vencida". */
    public function estado(string $texto): ?string
    {
        $buscado = Texto::normalizar($texto);

        foreach (Consulta::ESTADOS as $estado) {
            if (Texto::normalizar($estado) === $buscado) {
                return $estado;
            }
        }

        $porRaiz = [
            'borrador' => 'Borrador', 'vendid' => 'Vendida', 'venta' => 'Vendida', 'confirmad' => 'Confirmada',
            'emitid' => 'Confirmada', 'declinad' => Consulta::DECLINADA, 'cerrad' => Consulta::CERRADA,
            'vencid' => Consulta::VENCIDA, 'sin cotizar' => 'Sin cotizar',
        ];

        foreach ($porRaiz as $raiz => $estado) {
            if (str_starts_with($buscado, $raiz)) {
                return $estado;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------ materiales */

    /**
     * Cada forma de escribir un material (nombre o alias, sin tildes ni mayúsculas)
     * y cómo se llama en el catálogo. Las de menos de tres letras se dejan afuera:
     * "al", "ti" o "ni" son símbolos químicos, pero también palabras de cualquier frase.
     *
     * @return array<string, string>
     */
    public function materiales(): array
    {
        /** @var array<string, string> */
        return $this->memo['materiales'] ??= (function () {
            $indice = [];

            foreach (MaterialAlias::query()->join('materiales', 'materiales.id', '=', 'material_alias.material_id')
                ->where('materiales.activo', true)->get(['material_alias.alias', 'materiales.nombre']) as $fila) {
                $indice[Texto::normalizar($fila->alias)] ??= $fila->nombre;
            }

            // Los nombres van después: si un alias coincide con otro nombre, manda el nombre.
            foreach (Material::where('activo', true)->pluck('nombre') as $nombre) {
                $indice[Texto::normalizar($nombre)] = $nombre;
            }

            return array_filter($indice, fn ($canonico, $clave) => mb_strlen($clave) >= 3, ARRAY_FILTER_USE_BOTH);
        })();
    }

    /**
     * El material que se nombra dentro de un texto libre ("barras de monel" -> "Monel"): el nombre
     * más largo del catálogo que aparezca, o el código de aleación suelto ("316L") si figura en alguno.
     */
    public function materialEnTexto(string $texto): ?string
    {
        $indice = $this->materiales();
        $palabras = array_values(array_filter(explode(' ', Texto::normalizar($texto))));

        for ($i = 0; $i < count($palabras); $i++) {
            for ($largo = min(4, count($palabras) - $i); $largo >= 1; $largo--) {
                $frase = implode(' ', array_slice($palabras, $i, $largo));

                if (isset($indice[$frase])) {
                    return $indice[$frase];
                }
            }
        }

        foreach ($palabras as $palabra) {
            if (preg_match('/^[a-z]*\d[a-z0-9\-]*$/', $palabra) && (preg_match('/[a-z]/', $palabra) || strlen($palabra) >= 3)
                && array_filter(array_keys($indice), fn ($clave) => str_contains(" {$clave} ", " {$palabra} ")) !== []) {
                return $palabra;
            }
        }

        return null;
    }

    /* ---------------------------------------------------------------- común */

    /**
     * Cuál de las opciones es la que se nombra: la que se llama igual; si no, la
     * más corta que contiene lo escrito; si no, la más larga contenida en lo escrito.
     *
     * @param  array<int, string>  $opciones  id => nombre normalizado
     */
    private function mejor(array $opciones, string $texto): ?int
    {
        $buscado = Texto::normalizar($texto);

        if ($buscado === '') {
            return null;
        }

        if (($id = array_search($buscado, $opciones, true)) !== false) {
            return (int) $id;
        }

        $contienen = array_filter($opciones, fn ($n) => str_contains(" $n ", " $buscado "));

        if ($contienen !== []) {
            asort($contienen, SORT_STRING);
            uasort($contienen, fn ($a, $b) => mb_strlen($a) <=> mb_strlen($b));

            return (int) array_key_first($contienen);
        }

        $contenidas = array_filter($opciones, fn ($n) => mb_strlen($n) >= 4 && str_contains(" $buscado ", " $n "));

        if ($contenidas !== []) {
            uasort($contenidas, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

            return (int) array_key_first($contenidas);
        }

        return null;
    }

    /**
     * Las opciones cuyo nombre aparece en la frase, de la más larga a la más corta.
     *
     * @param  array<int, string>  $opciones
     * @return array<int, string>
     */
    private function nombradasEn(array $opciones, string $norm): array
    {
        $hallas = array_filter($opciones, fn ($n) => mb_strlen($n) >= 4 && str_contains(" $norm ", " $n "));
        uasort($hallas, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $hallas;
    }
}
