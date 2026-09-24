<?php

namespace Database\Seeders;

use App\Models\CondicionHabitual;
use App\Models\CondicionPago;
use App\Models\Forma;
use App\Models\Moneda;
use App\Models\Unidad;
use Illuminate\Database\Seeder;

/** Lo que hace falta para que el factor se calcule solo y las listas estén armadas. */
class CatalogosExtraSeeder extends Seeder
{
    public function run(): void
    {
        // --- cómo se calculan los kilos por metro de cada forma ---
        $formulas = [
            'BARRA REDONDA' => ['barra_redonda', 'Diametro'],
            'BARRA' => ['barra_redonda', 'Diametro'],
            'BARRA CUADRADA' => ['barra_cuadrada', 'Lado'],
            'BARRA HEXAGONAL' => ['barra_hexagonal', 'Entre caras'],
            'CAÑO' => ['cano', 'Diametro exterior y espesor'],
            'TUBO' => ['tubo', 'Diametro exterior y espesor'],
            'CHAPA' => ['chapa', 'Espesor y ancho'],
            'PLANCHUELA' => ['chapa', 'Espesor y ancho'],
            'ALAMBRE' => ['barra_redonda', 'Diametro'],
            // Estas no tienen cálculo: el factor se carga a mano.
            'DISCO' => ['ninguna', null],
            'ANILLO' => ['ninguna', null],
            'BRIDA' => ['ninguna', null],
            'PERFIL' => ['ninguna', null],
        ];

        foreach ($formulas as $nombre => [$formula, $necesarias]) {
            // La cuenta la pone CalculadoraSeeder; acá solo queda el texto de
            // ayuda con las medidas que hacen falta.
            Forma::where('nombre', $nombre)->update([
                'medidas_necesarias' => $necesarias,
            ]);
        }

        // --- la moneda que se propone sola ---
        Moneda::query()->update(['por_defecto' => false]);
        Moneda::where('nombre', 'DOLAR BILLETE BNA VENDEDOR')->update(['por_defecto' => true]);

        // --- en qué se vende y en qué se factura ---
        $unidades = [
            'UN' => [true, true, 1],
            'C/U' => [true, true, 2],
            'MT' => [true, false, 3],
            'KG' => [true, true, 4],
            'TN' => [false, true, 5],
        ];

        foreach ($unidades as $codigo => [$vender, $facturar, $orden]) {
            Unidad::where('codigo', $codigo)->update([
                'sirve_para_vender' => $vender,
                'sirve_para_facturar' => $facturar,
                'orden' => $orden,
            ]);
        }

        // --- condiciones de pago, para que dejen de escribirse a mano ---
        $pagos = [
            'Contado',
            'Contado contra entrega',
            '50% anticipo, saldo contra entrega',
            '30 dias fecha factura',
            '60 dias fecha factura',
            '90 dias fecha factura',
            'Credito a 30 dias',
            'Credito a 60 dias',
            'Cheque a 30 dias',
            'Transferencia anticipada',
        ];

        foreach ($pagos as $i => $nombre) {
            CondicionPago::firstOrCreate(['nombre' => $nombre], ['orden' => $i + 1]);
        }

        // --- condiciones que se repiten en las cotizaciones ---
        $condiciones = [
            'Plazo de entrega: segun disponibilidad',
            'Precios en Dolares Estadounidenses, mas IVA',
            'Mercaderia sujeta a venta previa',
            'Precios no incluyen flete',
            'Entrega en nuestro deposito de Palpa 3551',
            'Los precios pueden variar por diferencia de cambio',
            '1RA FILA X 1.10',
            '1RA FILA X 1.25',
        ];

        foreach ($condiciones as $i => $texto) {
            CondicionHabitual::firstOrCreate(['texto' => $texto], ['orden' => $i + 1]);
        }
    }
}
