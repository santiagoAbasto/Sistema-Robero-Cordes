<?php

namespace App\Services;

use App\Models\Forma;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Unidad;

/**
 * Lee el texto que mandó el cliente y arma las líneas.
 *
 * Se pega el mail o el WhatsApp tal cual y el sistema propone las líneas ya
 * separadas en cantidad, unidad, material, forma y medida. Nada se guarda
 * solo: es una propuesta que la persona revisa, corrige y recién ahí confirma.
 */
class LectorDeSolicitud
{
    /** Donde guarda la linea, en milimetros, cada medida de la forma. */
    private const COLUMNA = [
        'diameter' => 'diametro_mm', 'outer' => 'diametro_mm',
        'side' => 'diametro_mm', 'across' => 'diametro_mm',
        'wall' => 'espesor_mm', 'height' => 'espesor_mm',
        'width' => 'ancho_mm', 'length' => 'largo_mm',
    ];

    /**
     * Como escriben las formas cuando no escriben el nombre entero.
     *
     * Salio de leer las descripciones del archivo: "BAR RED", "ALA", "FLEJE".
     * Solo abrevia formas que ya estan en el catalogo; no inventa ninguna.
     */
    private const ABREVIATURAS = [
        'BARRA REDONDA' => ['BAR RED', 'BARRED', 'BARRA RED', 'REDONDO'],
        'BARRA HEXAGONAL' => ['BAR HEX', 'HEXAG'],
        'BARRA CUADRADA' => ['BAR CUAD', 'CUADRADA'],
        'BARRA RED. / VARILLA' => ['VARILLA'],
        'ALAMBRE' => ['ALAMB', 'ALA'],
        'CAÑO' => ['CANIO', 'SCH'],
        'CHAPA' => ['PLACA'],
        'PLANCHUELA' => ['PLANCH', 'FLEJE'],
        'TUBO' => ['TUB'],
    ];

    /** @return array{lineas: array<int, array<string, mixed>>, sin_reconocer: array<int, string>} */
    public function interpretar(string $texto): array
    {
        $materiales = Material::with('alias')->where('activo', true)->get();
        $formas = Forma::where('activo', true)->get();
        $unidades = Unidad::where('activo', true)->get();

        $lineas = [];
        $sinReconocer = [];

        foreach ($this->separarRenglones($texto) as $renglon) {
            $linea = $this->leerRenglon($renglon, $materiales, $formas, $unidades);

            if ($linea === null) {
                $sinReconocer[] = $renglon;

                continue;
            }

            $lineas[] = $linea;
        }

        return ['lineas' => $lineas, 'sin_reconocer' => $sinReconocer];
    }

    /** Un renglón por línea de pedido. Se descartan saludos y firmas. */
    private function separarRenglones(string $texto): array
    {
        $renglones = preg_split('/[\r\n]+|(?<=\))\s*[,;]\s*/u', $texto) ?: [];

        return array_values(array_filter(array_map('trim', $renglones), function ($r) {
            if (mb_strlen($r) < 6) {
                return false;
            }

            // Saludos, despedidas y encabezados de mail.
            $ruido = '/^(hola|buen[oa]s?|estimad|gracias|saludos|atte|de:|para:|asunto:|enviado)/iu';

            return ! preg_match($ruido, $r);
        }));
    }

    private function leerRenglon($renglon, $materiales, $formas, $unidades): ?array
    {
        $normalizado = $this->normalizar($renglon);

        $material = $this->buscarMaterial($normalizado, $materiales);
        $forma = $this->buscarForma($normalizado, $renglon, $formas);
        [$cantidad, $unidad] = $this->buscarCantidad($normalizado, $unidades);
        $medidas = $this->buscarMedidas($renglon, $forma);

        /*
          Que hace que un renglón sea una línea de pedido.

          Antes lo decidia el material solo: sin material, el renglón se
          descartaba entero y con el se iban la forma, la cantidad y las
          medidas que si se habian leido. "1 caño inoxidable 316L 4\" SCH 40
          x 6 m" desaparecia de la pantalla en vez de entrar con todo cargado
          menos el material, que es un desplegable al lado.

          Tirar trabajo hecho es peor que dejar un campo vacio: la linea se
          revisa igual antes de guardar.
        */
        $esPedido = $material !== null
            || ($forma !== null && ($cantidad !== null || $medidas['texto'] !== null));

        if (! $esPedido) {
            return null;
        }

        return [
            'descripcion' => trim($renglon),
            'material_id' => $material?->id,
            'material' => $material?->nombre,
            'forma_id' => $forma?->id,
            'forma' => $forma?->nombre,
            'dimensiones' => $medidas['texto'],
            'diametro_mm' => $medidas['diametro_mm'],
            'espesor_mm' => $medidas['espesor_mm'],
            'ancho_mm' => $medidas['ancho_mm'],
            'largo_mm' => $medidas['largo_mm'],
            'cantidad' => $cantidad,
            'unidad_venta_id' => $unidad?->id,
            'unidad' => $unidad?->codigo,
            // Lo que pidió el cliente arranca igual a lo que se va a cotizar.
            'igual_a_lo_pedido' => true,
        ];
    }

    private function normalizar(string $texto): string
    {
        $sinAcentos = strtr(mb_strtoupper($texto, 'UTF-8'), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ]);

        // Quita separadores para que "HAST C-276" y "HASTC276" se parezcan.
        return preg_replace('/[^A-Z0-9]/', '', $sinAcentos) ?? '';
    }

    /** Busca por el nombre y por todas las formas en que lo escriben. */
    private function buscarMaterial(string $normalizado, $materiales): ?Material
    {
        $mejor = null;
        $largoMejor = 0;

        foreach ($materiales as $material) {
            $candidatos = collect([$material->nombre])
                ->merge($material->alias->pluck('alias'))
                ->map(fn ($a) => $this->normalizar($a))
                ->filter();

            foreach ($candidatos as $candidato) {
                if (! $this->identificaAlgo($candidato)) {
                    continue;
                }

                // El más largo gana: "AISI 316TI" antes que "AISI 316".
                if (str_contains($normalizado, $candidato) && mb_strlen($candidato) > $largoMejor) {
                    $mejor = $material;
                    $largoMejor = mb_strlen($candidato);
                }
            }
        }

        return $mejor;
    }

    /**
     * La forma, buscada en el catalogo y no en una lista escrita a mano.
     *
     * Antes eran ocho sinonimos fijos. El catalogo tiene diecinueve formas
     * activas, asi que once no se reconocian nunca: una arandela, un disco,
     * un anillo, una brida o un alambre entraban sin forma. Y sin forma no
     * hay donde poner las medidas, y sin medidas no hay peso ni precio.
     *
     * Gana el nombre mas largo que aparezca escrito, igual que con el
     * material: asi "BARRA REDONDA" le gana a "BARRA" donde estan las dos.
     */
    /**
     * Si ese nombre o alias alcanza para reconocer un material por si solo.
     *
     * El catalogo tiene entradas de dos letras —"SC" es Scandio— y de puros
     * numeros —"50"—. Buscadas por pedazo de texto enganchan cualquier cosa:
     * "CAÑO S/C 4\" SCH 40" entraba como Scandio por el SCH, y un
     * "RECTANGULO 50 X 3000" como el material 50.
     *
     * Siguen en el catalogo y se eligen a mano en la lista; lo que no se
     * puede es adivinarlas desde el texto que escribio el cliente.
     */
    private function identificaAlgo(string $candidato): bool
    {
        // Una designacion: tiene letras y numeros. F138, X750, 316L, C276.
        if (preg_match('/^(?=.*[A-Z])(?=.*\d)[A-Z0-9]+$/', $candidato)) {
            return true;
        }

        // O una palabra de verdad: cuatro letras para arriba.
        return ctype_alpha($candidato) && mb_strlen($candidato) >= 4;
    }

    private function buscarForma(string $normalizado, string $renglon, $formas): ?Forma
    {
        $mejor = null;
        $largoMejor = 0;

        foreach ($formas as $forma) {
            $candidato = $this->normalizar($forma->nombre);

            // Menos de cuatro letras no alcanza para distinguir nada.
            if (mb_strlen($candidato) < 4 || mb_strlen($candidato) <= $largoMejor) {
                continue;
            }

            if (str_contains($normalizado, $candidato)) {
                $mejor = $forma;
                $largoMejor = mb_strlen($candidato);
            }
        }

        if ($mejor !== null) {
            return $mejor;
        }

        /*
          Recien si no escribieron el nombre entero, las abreviaturas.

          Van sobre el texto con los espacios puestos y pegadas a un borde de
          palabra: "ALA" es alambre en "TIT GR4 ALA Ø 3.18", pero adentro de
          otra palabra no es nada. Sobre el texto sin espacios —que es con lo
          que se busca el material— "ALA" aparece en cualquier lado.

          BARRA a secas no esta y no es un olvido: el catalogo tiene activas
          BARRA REDONDA y BARRA RED. / VARILLA, y cual de las dos es queda
          para que lo diga CORDES. Adivinar una define el peso que se
          factura.
        */
        $conEspacios = $this->conEspacios($renglon);

        foreach (self::ABREVIATURAS as $nombre => $claves) {
            foreach ($claves as $clave) {
                if (! preg_match('/\b'.preg_quote($clave, '/').'\b/', $conEspacios)) {
                    continue;
                }

                $forma = $formas->firstWhere('nombre', $nombre);

                if ($forma) {
                    return $forma;
                }
            }
        }

        return null;
    }

    /** El texto en mayusculas y sin simbolos, pero con los espacios. */
    private function conEspacios(string $texto): string
    {
        $mayusculas = strtr(mb_strtoupper($texto, 'UTF-8'), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ]);

        return trim(preg_replace('/[^A-Z0-9]+/', ' ', $mayusculas) ?? '');
    }

    /** "6 UN", "2 c/u", "3 metros" al principio del renglón. */
    private function buscarCantidad(string $normalizado, $unidades): array
    {
        if (! preg_match('/^(\d+(?:[.,]\d+)?)/', $normalizado, $m)) {
            return [null, null];
        }

        $cantidad = (float) str_replace(',', '.', $m[1]);

        $porTexto = [
            'METRO' => 'MT', 'METROS' => 'MT', 'MTS' => 'MT', 'MT' => 'MT',
            'KILO' => 'KG', 'KILOS' => 'KG', 'KG' => 'KG',
            'CU' => 'C/U', 'UN' => 'UN', 'UNIDAD' => 'UN', 'UNIDADES' => 'UN',
        ];

        foreach ($porTexto as $texto => $codigo) {
            if (preg_match('/^\d+(?:[.,]\d+)?'.$texto.'/', $normalizado)) {
                return [$cantidad, $unidades->firstWhere('codigo', $codigo)];
            }
        }

        return [$cantidad, $unidades->firstWhere('codigo', 'UN')];
    }

    /**
     * Las medidas escritas, cada una en el campo que le toca.
     *
     * El orden lo pone la forma, no este metodo: una barra redonda escrita
     * "38.1 X 145" es diametro y largo, y una chapa escrita "2 X 1000 X 2000"
     * es espesor, ancho y largo. Antes se tomaban siempre los dos primeros
     * numeros como diametro y largo, asi que la chapa entraba con 2 mm de
     * diametro y 1000 de largo, y el 2000 se perdia. De ahi sale el peso.
     *
     * @return array{texto: ?string, diametro_mm: ?float, espesor_mm: ?float, ancho_mm: ?float, largo_mm: ?float}
     */
    private function buscarMedidas(string $renglon, ?Forma $forma): array
    {
        $medidas = [
            'texto' => null, 'diametro_mm' => null,
            'espesor_mm' => null, 'ancho_mm' => null, 'largo_mm' => null,
        ];

        $numeros = [];

        // "38.1 X 145 MM" · "2 X 1000 X 2000" · "DIA 65 X 145MM" · "Ø 10 X 2000"
        $serie = '/(?:DIA\s*|Ø\s*)?\d+(?:[.,]\d+)?(?:\s*(?:MM\s*)?[xX]\s*\d+(?:[.,]\d+)?){1,2}(?:\s*MM)?/iu';

        if (preg_match($serie, $renglon, $m)) {
            $medidas['texto'] = trim($m[0]);
            $limpio = preg_replace('/\s*(?:MM|DIA|Ø)\s*/iu', ' ', $m[0]) ?? '';
            $numeros = array_values(array_filter(
                array_map('trim', preg_split('/[xX]/', $limpio) ?: []),
                fn ($n) => $n !== '',
            ));
        } elseif (preg_match('/(?:DIA\s*|Ø\s*)?(\d+(?:[.,]\d+)?)\s*MM/iu', $renglon, $m)) {
            // Una sola medida, pero con su unidad puesta: "ALAMBRE 0,70 MM".
            $medidas['texto'] = trim($m[0]);
            $numeros = [$m[1]];
        }

        /*
          Los caños vienen en pulgadas y con schedule. La medida se guarda
          como la escriben —"4\" SCH 40 X 6000"— porque asi la pide el cliente
          y asi la busca el proveedor; pasarla a milimetros es otra cosa.
        */
        if (preg_match('/\d+\s*"\s*(?:SCH\s*\d+)?(?:\s*[xX]\s*\d+\s*(?:MM)?)?/u', $renglon, $m)) {
            $medidas['texto'] = trim($m[0]);

            return $medidas;
        }

        /*
          Sin forma reconocida, dos numeros se leen como diametro y largo.

          Es lo que son en las seis formas del catalogo que piden dos medidas:
          primero la seccion, despues el largo. Con tres numeros no se
          arriesga —cual de ellos es el espesor depende de la forma— y quedan
          vacios, con el texto entero a la vista para completarlos.
        */
        $orden = $forma?->ordenEnQueSeEscriben()
            ?? (count($numeros) === 2 ? ['diameter', 'length'] : []);

        foreach ($numeros as $i => $n) {
            $columna = self::COLUMNA[$orden[$i] ?? ''] ?? null;

            if ($columna === null || $medidas[$columna] !== null) {
                continue;
            }

            $medidas[$columna] = (float) str_replace(',', '.', $n);
        }

        return $medidas;
    }
}
