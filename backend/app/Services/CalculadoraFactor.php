<?php

namespace App\Services;

use App\Models\CanoEstandar;
use App\Models\Forma;
use App\Models\Material;

/**
 * Los kilos que pesa UNA unidad de venta, según su forma.
 *
 * Cuando se cotiza en una unidad y se factura por kilo hace falta este número.
 * Y depende de en qué se vende:
 *
 *  · por metro  → los kilos de un metro (largo = 1000 mm)
 *  · por pieza  → los kilos de esa pieza, con su largo real
 *
 * No es un detalle. Una barra Ø127 de titanio pesa 57,13 kg el metro y 1,451 kg
 * si la pieza mide 25,4 mm. Usar el de metro para vender por unidad multiplica
 * la factura por cuarenta.
 * El sistema lo propone calculado y siempre se puede corregir a mano, porque
 * no siempre da igual: depende del lote.
 *
 * La cuenta no está acá: se la pide a la calculadora de peso, que resuelve la
 * fórmula guardada con cada forma. Antes esta clase tenía su propia copia de
 * las fórmulas y podía terminar diciendo algo distinto que la calculadora para
 * la misma barra.
 *
 * Si a la forma le falta alguna medida —o no tiene fórmula— devuelve null y
 * la línea queda marcada como "sin factor": no se calcula el importe ni se
 * inventa un número.
 */
class CalculadoraFactor
{
    public function __construct(private CalculadoraDePeso $calculadora) {}

    /**
     * @return array{factor: float|null, motivo: string|null, falta: string|null}
     */
    public function calcular(
        ?Material $material,
        ?Forma $forma,
        ?float $diametroMm,
        ?float $espesorMm,
        ?float $anchoMm,
        ?CanoEstandar $cano = null,
        ?float $largoMm = null,
    ): array {
        // Si la forma necesita un largo y se vende por pieza, sin ese largo no
        // hay factor posible. Antes se usaba un metro por defecto y salia un
        // numero enorme que nadie revisaba.
        if ($largoMm !== null && $largoMm <= 0 && $this->necesitaLargo($forma)) {
            return ['factor' => null, 'motivo' => 'Falta el largo de la pieza', 'falta' => 'largo'];
        }

        $resultado = $this->calculadora->calcular(
            $material,
            $forma,
            $this->medidas($forma, $diametroMm, $espesorMm, $anchoMm, $largoMm),
            piezas: 1,
            cano: $cano,
        );

        return [
            // Una sola pieza: su peso ES el factor de esa unidad de venta.
            'factor' => $resultado['ok'] ? round((float) $resultado['peso_por_pieza_kg'], 4) : null,
            'motivo' => $resultado['motivo'],
            'falta' => $resultado['ok'] ? null : $forma?->medidas_necesarias,
        ];
    }

    /**
     * Las tres medidas sueltas de la línea, puestas donde las espera cada forma.
     *
     * La línea guarda diametro/espesor/ancho porque es lo que se carga a mano.
     * Cada forma las nombra a su manera —el diámetro de una cuadrada es el
     * lado, el de una hexagonal la distancia entre caras— así que hay que
     * acomodarlas antes de resolver la fórmula.
     *
     * El largo: el de la pieza si se vende por unidad, o un metro si se vende
     * por metro (que es lo que significa "kilos por metro").
     */
    private function medidas(
        ?Forma $forma,
        ?float $diametroMm,
        ?float $espesorMm,
        ?float $anchoMm,
        ?float $largoMm = null,
    ): array {
        $mm = fn (?float $v) => ['valor' => $v ?? 0, 'unidad' => 'mm'];
        $largo = ['valor' => $largoMm ?? 1000, 'unidad' => 'mm'];

        $medidas = ['length' => $largo];

        foreach ($forma?->camposDelCalculo() ?? [] as $campo) {
            $medidas[$campo['clave']] = match ($campo['clave']) {
                'diameter', 'outer', 'side', 'across' => $mm($diametroMm),
                'wall', 'height' => $mm($espesorMm),
                'width' => $mm($anchoMm),
                'length' => $largo,
                default => $mm(null),
            };
        }

        return $medidas;
    }

    /** Si la forma lleva largo entre sus medidas. Un disco o una esfera no. */
    private function necesitaLargo(?Forma $forma): bool
    {
        foreach ($forma?->camposDelCalculo() ?? [] as $campo) {
            if ($campo['clave'] === 'length') {
                return true;
            }
        }

        return false;
    }
}
