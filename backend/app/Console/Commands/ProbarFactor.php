<?php

namespace App\Console\Commands;

use App\Models\Forma;
use App\Models\Material;
use App\Services\CalculadoraFactor;
use Illuminate\Console\Command;

class ProbarFactor extends Command
{
    protected $signature = 'cordes:factor';

    protected $description = 'Prueba el calculo automatico del factor por forma';

    public function handle(CalculadoraFactor $calc): int
    {
        $casos = [
            ['TITANIO GR2', 'BARRA REDONDA', 50.0, null, null, 'esperado ~10,13'],
            ['HASTELLOY C-276', 'BARRA REDONDA', 38.1, null, null, ''],
            ['AISI 316', 'BARRA CUADRADA', 40.0, null, null, ''],
            ['NIQUEL 201', 'CAÑO', 114.3, 6.02, null, 'cano 4" SCH40'],
            ['AISI 304', 'CHAPA', null, 3.0, 1000.0, 'chapa 3mm x 1m'],
            ['TITANIO GR2', 'DISCO', 100.0, null, null, 'sin formula'],
            ['HASTELLOY C-276', 'CAÑO', 114.3, null, null, 'falta espesor'],
        ];

        foreach ($casos as [$mat, $forma, $dia, $esp, $ancho, $nota]) {
            $r = $calc->calcular(
                Material::where('nombre', $mat)->first(),
                Forma::where('nombre', $forma)->first(),
                $dia, $esp, $ancho,
            );

            $valor = $r['factor'] !== null
                ? number_format($r['factor'], 4, ',', '.').' kg/m'
                : 'SIN FACTOR — '.$r['motivo'].($r['falta'] ? ' (falta: '.$r['falta'].')' : '');

            $this->line(sprintf('  %-16s %-15s %-40s %s', $mat, $forma, $valor, $nota));
        }

        return self::SUCCESS;
    }
}
