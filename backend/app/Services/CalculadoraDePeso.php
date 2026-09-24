<?php

namespace App\Services;

use App\Models\CanoEstandar;
use App\Models\Forma;
use App\Models\Material;

/**
 * Cuanto pesa lo que se esta cotizando.
 *
 * Toma el material (por su densidad), la forma (por su formula), las medidas
 * con la unidad en que las cargaron y cuantas piezas son. Devuelve el peso y,
 * junto con el, la foto de todo lo que uso para llegar ahi.
 *
 * Esa foto es lo importante: se guarda en la linea de la cotizacion. Si mañana
 * se corrige una densidad o se cambia una formula, las cotizaciones ya hechas
 * siguen diciendo lo que dijeron el dia que se hicieron.
 */
class CalculadoraDePeso
{
    /** Todo se pasa a milimetros antes de resolver la formula. */
    public const A_MILIMETROS = [
        'mm' => 1.0,
        'cm' => 10.0,
        'm' => 1000.0,
        'in' => 25.4,
        'ft' => 304.8,
    ];

    /** El peso se calcula en kilos y despues se pasa a la unidad que pidieron. */
    public const DESDE_KILOS = [
        'kg' => 1.0,
        'g' => 1000.0,
        'lb' => 2.2046226218,
        'ton' => 0.001,
    ];

    public function __construct(private EvaluadorDeFormulas $evaluador) {}

    /**
     * La formula da el volumen de UNA pieza. El total sale de multiplicar por
     * la cantidad, y los dos quedan guardados: sirven para cosas distintas.
     * El unitario se compara contra una tabla o el plano; el total es el que
     * va al flete y a los kilos de la cotizacion.
     *
     * @param  array<string, array{valor: float|string|null, unidad: string}>  $medidas
     * @return array{
     *     ok: bool, motivo: ?string,
     *     volumen_por_pieza_cm3: ?float, volumen_total_cm3: ?float,
     *     peso_por_pieza_kg: ?float, peso_total_kg: ?float,
     *     resultado: ?float, unidad_resultado: string,
     *     densidad_g_cm3: ?float, piezas: float, medidas: array,
     *     material_id: ?int, material: ?string, uns: ?string, w_nr: ?string,
     *     forma_id: ?int, forma: ?string, formula: ?string,
     *     cano_id: ?int, cano: ?string
     * }
     */
    public function calcular(
        ?Material $material,
        ?Forma $forma,
        array $medidas,
        float $piezas = 1,
        string $unidadResultado = 'kg',
        ?CanoEstandar $cano = null,
    ): array {
        $foto = $this->fotoVacia($material, $forma, $cano, $unidadResultado, $piezas);

        if (! $material) {
            return $this->sinPeso($foto, 'Falta elegir el material');
        }

        if (! $material->densidad || (float) $material->densidad <= 0) {
            return $this->sinPeso($foto, 'No sabemos la densidad de '.$material->nombre);
        }

        if (! $forma) {
            return $this->sinPeso($foto, 'Falta elegir la forma');
        }

        // blank() y no (!): una expresion de solo espacios es truthy en PHP y
        // se colaria a la evaluacion como si fuera una cuenta.
        if (blank($forma->expresion)) {
            return $this->sinPeso($foto, 'La forma '.$forma->nombre.' no tiene calculo automatico');
        }

        if ($piezas <= 0) {
            return $this->sinPeso($foto, 'La cantidad de piezas tiene que ser mayor que cero');
        }

        [$valores, $detalle] = $this->aMilimetros($medidas, $forma, $cano);
        $foto['medidas'] = $detalle;

        if ($falta = $this->medidaQueFalta($forma, $valores)) {
            return $this->sinPeso($foto, 'Falta cargar '.$falta);
        }

        if ($problema = $this->problemaDeGeometria($valores)) {
            return $this->sinPeso($foto, $problema);
        }

        // Antes de resolverla: que la formula no nombre medidas que la forma no
        // pide. El evaluador tomaria cero para esas y devolveria un numero —o
        // un cero— culpando a las medidas, cuando el problema es la cuenta.
        if ($falta = $this->medidaQueLaFormulaNombraYNoExiste($forma, $valores)) {
            return $this->sinPeso(
                $foto,
                'La formula de '.$forma->nombre.' necesita revision: nombra '.$falta
                    .', que no esta entre las medidas de la forma. Avisá a quien administra las formas.'
            );
        }

        // La formula devuelve el volumen de una sola pieza.
        $porPieza = $this->evaluador->evaluarONada($forma->expresion, $valores);

        if ($porPieza === null) {
            return $this->sinPeso(
                $foto,
                'La formula de '.$forma->nombre.' necesita revision: no se pudo resolver. '
                    .'Avisá a quien administra las formas.'
            );
        }

        if ($porPieza <= 0) {
            return $this->sinPeso($foto, 'Con esas medidas el volumen da cero o negativo');
        }

        $densidad = (float) $material->densidad;
        $pesoPorPieza = $porPieza * $densidad / 1000;

        return array_merge($foto, [
            'ok' => true,
            'motivo' => null,
            'volumen_por_pieza_cm3' => round($porPieza, 4),
            'volumen_total_cm3' => round($porPieza * $piezas, 4),
            'peso_por_pieza_kg' => round($pesoPorPieza, 4),
            'peso_total_kg' => round($pesoPorPieza * $piezas, 4),
            'resultado' => round($pesoPorPieza * $piezas * (self::DESDE_KILOS[$unidadResultado] ?? 1.0), 4),
        ]);
    }

    /**
     * Los kilos que pesa un metro.
     *
     * Es la misma cuenta de siempre, resuelta con el mismo motor: se calcula
     * cuanto pesa un metro de esa forma. Asi el factor y el peso total no se
     * pueden contradecir.
     */
    public function porMetro(?Material $material, ?Forma $forma, array $medidas): array
    {
        $conLargo = array_merge($medidas, ['length' => ['valor' => 1000, 'unidad' => 'mm']]);

        $r = $this->calcular($material, $forma, $conLargo, 1);

        return [
            'factor' => $r['ok'] ? round($r['peso_por_pieza_kg'], 4) : null,
            'motivo' => $r['motivo'],
        ];
    }

    // ------------------------------------------------------------- auxiliares

    /**
     * Pasa cada medida a milimetros y deja anotado como venia.
     *
     * @return array{0: array<string, float>, 1: array<string, array>}
     */
    private function aMilimetros(array $medidas, Forma $forma, ?CanoEstandar $cano): array
    {
        $valores = [];
        $detalle = [];

        foreach ($forma->camposDelCalculo() as $campo) {
            $clave = $campo['clave'];
            $cargado = $medidas[$clave] ?? null;
            $unidad = $cargado['unidad'] ?? 'mm';
            $valor = (float) ($cargado['valor'] ?? 0);
            $enMm = $valor * (self::A_MILIMETROS[$unidad] ?? 1.0);

            $valores[$clave] = $enMm;
            $detalle[$clave] = [
                'label' => $campo['label'],
                'valor' => $valor,
                'unidad' => $unidad,
                'valor_mm' => round($enMm, 4),
            ];
        }

        // El caño reemplaza el diametro exterior y la pared: no se cargan.
        if ($forma->usa_cano && $cano) {
            $valores['outer'] = (float) $cano->diametro_mm;
            $valores['wall'] = (float) $cano->pared_mm;

            $detalle['outer'] = ['label' => 'Diametro exterior', 'valor' => (float) $cano->diametro_mm, 'unidad' => 'mm', 'valor_mm' => (float) $cano->diametro_mm];
            $detalle['wall'] = ['label' => 'Espesor de pared', 'valor' => (float) $cano->pared_mm, 'unidad' => 'mm', 'valor_mm' => (float) $cano->pared_mm];
        }

        return [$valores, $detalle];
    }

    private function medidaQueFalta(Forma $forma, array $valores): ?string
    {
        foreach ($forma->camposDelCalculo() as $campo) {
            if (($valores[$campo['clave']] ?? 0) > 0) {
                continue;
            }

            // En un caño, el diametro y la pared se pueden completar eligiendo
            // uno de la lista: se avisa que hay dos caminos, no uno solo.
            if ($forma->usa_cano && in_array($campo['clave'], ['outer', 'wall'], true)) {
                return 'el caño: elegilo de la lista o cargá el diametro y la pared';
            }

            return mb_strtolower($campo['label']);
        }

        return null;
    }

    /**
     * Las medidas que la formula nombra pero la forma no pide.
     *
     * No deberia pasar —al guardar una forma se valida— pero una formula puede
     * quedar rota por una migracion, una importacion o alguien tocando la base.
     * Y una medida que no existe vale cero para el evaluador: la cuenta sigue
     * andando y devuelve un peso equivocado sin protestar.
     */
    private function medidaQueLaFormulaNombraYNoExiste(Forma $forma, array $valores): ?string
    {
        try {
            $usadas = $this->evaluador->variables($forma->expresion);
        } catch (\Throwable) {
            // La formula ni siquiera se puede leer: lo resuelve el paso siguiente.
            return null;
        }

        $sinDeclarar = array_diff($usadas, array_keys($valores));

        return $sinDeclarar === [] ? null : implode(' y ', $sinDeclarar);
    }

    /** Lo que la formula no puede avisar por su cuenta porque daria un numero igual. */
    private function problemaDeGeometria(array $valores): ?string
    {
        if (isset($valores['outer'], $valores['inner']) && $valores['inner'] >= $valores['outer']) {
            return 'El diametro interior tiene que ser menor que el exterior';
        }

        if (isset($valores['outer'], $valores['wall']) && $valores['wall'] * 2 >= $valores['outer']) {
            return 'La pared no puede llegar a la mitad del diametro: no quedaria agujero';
        }

        return null;
    }

    private function fotoVacia(
        ?Material $material,
        ?Forma $forma,
        ?CanoEstandar $cano,
        string $unidadResultado,
        float $piezas,
    ): array {
        return [
            'ok' => false,
            'motivo' => null,
            'material_id' => $material?->id,
            'material' => $material?->nombre,
            'densidad_g_cm3' => $material?->densidad ? (float) $material->densidad : null,
            'uns' => $material?->uns,
            'w_nr' => $material?->w_nr,
            'forma_id' => $forma?->id,
            'forma' => $forma?->nombre,
            'formula' => $forma?->expresion,
            'cano_id' => $cano?->id,
            'cano' => $cano ? $cano->nombre.' SCH '.$cano->schedule : null,
            'medidas' => [],
            'piezas' => $piezas,
            'volumen_por_pieza_cm3' => null,
            'volumen_total_cm3' => null,
            'peso_por_pieza_kg' => null,
            'peso_total_kg' => null,
            'resultado' => null,
            'unidad_resultado' => $unidadResultado,
        ];
    }

    private function sinPeso(array $foto, string $motivo): array
    {
        return array_merge($foto, ['ok' => false, 'motivo' => $motivo]);
    }
}
