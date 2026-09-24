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

        // El material es lo que decide si el renglón es una línea de pedido.
        $material = $this->buscarMaterial($normalizado, $materiales);

        if (! $material) {
            return null;
        }

        $forma = $this->buscarForma($normalizado, $formas);
        [$cantidad, $unidad] = $this->buscarCantidad($normalizado, $unidades);
        $medidas = $this->buscarMedidas($renglon);

        return [
            'descripcion' => trim($renglon),
            'material_id' => $material->id,
            'material' => $material->nombre,
            'forma_id' => $forma?->id,
            'forma' => $forma?->nombre,
            'dimensiones' => $medidas['texto'],
            'diametro_mm' => $medidas['diametro'],
            'largo_mm' => $medidas['largo'],
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
                // El más largo gana: "AISI 316TI" antes que "AISI 316".
                if (str_contains($normalizado, $candidato) && mb_strlen($candidato) > $largoMejor) {
                    $mejor = $material;
                    $largoMejor = mb_strlen($candidato);
                }
            }
        }

        return $mejor;
    }

    private function buscarForma(string $normalizado, $formas): ?Forma
    {
        // Cómo lo escriben ellos, además del nombre del catálogo.
        $sinonimos = [
            'BARRA REDONDA' => ['BARRAREDONDA', 'BARRED', 'REDONDO', 'BARREDONDA'],
            'CAÑO' => ['CANO', 'CANO', 'TUBOSCH', 'SCH'],
            'CHAPA' => ['CHAPA', 'PLACA'],
            'PLANCHUELA' => ['PLANCHUELA', 'FLEJE'],
            'BARRA HEXAGONAL' => ['HEXAGONAL', 'BARHEX'],
            'BARRA CUADRADA' => ['CUADRADA', 'BARCUAD'],
            'TUBO' => ['TUBO'],
            'BARRA' => ['BARRA'],
        ];

        foreach ($sinonimos as $nombre => $claves) {
            foreach ($claves as $clave) {
                if (str_contains($normalizado, $clave)) {
                    $forma = $formas->firstWhere('nombre', $nombre);

                    if ($forma) {
                        return $forma;
                    }
                }
            }
        }

        return null;
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

    /** Saca la medida escrita y, si puede, el diámetro y el largo en mm. */
    private function buscarMedidas(string $renglon): array
    {
        $texto = null;
        $diametro = null;
        $largo = null;

        // 38.1 X 145 MM  ·  DIA 65 X 145  ·  4" SCH 40 X 3000 MM
        if (preg_match('/(?:DIA\s*)?(\d+(?:[.,]\d+)?)\s*(?:MM)?\s*[xX]\s*(\d+(?:[.,]\d+)?)\s*(?:MM)?/u', $renglon, $m)) {
            $texto = trim($m[0]);
            $diametro = (float) str_replace(',', '.', $m[1]);
            $largo = (float) str_replace(',', '.', $m[2]);
        } elseif (preg_match('/(?:DIA\s*)?(\d+(?:[.,]\d+)?)\s*MM/iu', $renglon, $m)) {
            $texto = trim($m[0]);
            $diametro = (float) str_replace(',', '.', $m[1]);
        }

        // Los caños vienen en pulgadas: la medida se guarda como la escriben.
        if (preg_match('/\d+\s*"\s*(?:SCH\s*\d+)?(?:\s*[xX]\s*\d+\s*(?:MM)?)?/u', $renglon, $m)) {
            $texto = trim($m[0]);
        }

        return ['texto' => $texto, 'diametro' => $diametro, 'largo' => $largo];
    }
}
