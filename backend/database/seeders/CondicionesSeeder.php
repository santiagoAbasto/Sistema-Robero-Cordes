<?php

namespace Database\Seeders;

use App\Models\CondicionHabitual;
use Illuminate\Database\Seeder;

/**
 * Las condiciones que CORDES manda de verdad, en sus dos juegos.
 *
 * El texto es el que paso la empresa el 09-09-2026, tal cual: no es una
 * redaccion propia. Son terminos que obligan legalmente, asi que se copian
 * literales y se corrigen desde la pantalla, no desde el codigo.
 *
 * Hay dos juegos porque son dos negocios distintos:
 *
 *  · IMPORTACION — 120 dias corridos de entrega, 50% de anticipo con el
 *    pedido, y el parrafo de los Reglamentos Tecnicos.
 *  · STOCK — 2 a 4 dias habiles, contado contra entrega, y la forma de pago
 *    resumida en un parrafo en vez de la lista.
 *
 * No hay uno por defecto a proposito: elegir mal manda una oferta con el plazo
 * del otro caso. La cotizacion guarda cual se uso.
 */
class CondicionesSeeder extends Seeder
{
    public const IMPORTACION = 'Importacion';

    public const STOCK = 'Stock';

    public function run(): void
    {
        foreach ([self::IMPORTACION, self::STOCK] as $juego) {
            foreach ($this->bloques($juego) as $orden => [$titulo, $texto]) {
                CondicionHabitual::updateOrCreate(
                    ['juego' => $juego, 'titulo' => $titulo],
                    [
                        'texto' => $texto,
                        'orden' => $orden + 1,
                        'activo' => true,
                        'por_defecto' => true,
                    ],
                );
            }
        }

        $this->sacarLasDeRelleno();

        // Aparte de los dos juegos: se agrega cuando el cliente pide el ensayo.
        CondicionHabitual::updateOrCreate(
            ['juego' => null, 'titulo' => 'Ensayo'],
            [
                'texto' => 'La certificación de la materia prima empleada incluye resultado '
                    .'del ensayo de Huey.',
                'orden' => 99,
                'activo' => true,
                'por_defecto' => false,
            ],
        );
    }

    /**
     * Las condiciones sueltas de las primeras versiones salen de la lista.
     *
     * Eran tres frases de relleno —"Plazo de entrega: segun disponibilidad" y
     * dos mas— que se cargaron antes de tener el texto real de la empresa. Sin
     * titulo y sin juego, aparecian mezcladas con las de verdad al momento de
     * agregar una condicion, y ensuciaban la pantalla.
     *
     * Se desactivan, no se borran: si alguna cotizacion vieja las usó, su
     * texto quedó copiado en la cotizacion y no se toca.
     */
    private function sacarLasDeRelleno(): void
    {
        CondicionHabitual::whereNull('titulo')->update(['activo' => false]);

        // El "Ensayo" quedó pegado a Importacion cuando se separaron los dos
        // juegos. No es de un juego ni del otro: se agrega cuando el cliente
        // lo pide, sea importacion o stock. Queda uno solo, sin juego.
        CondicionHabitual::where('titulo', 'Ensayo')->whereNotNull('juego')->delete();
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function bloques(string $juego): array
    {
        $importacion = $juego === self::IMPORTACION;

        return [
            [
                'Cantidades',
                'La modificación o supresión de algún ítem o de las cantidades cotizadas '
                ."harán necesaria una nueva cotización. No válida para compras parciales.\n"
                .'Cuando se coticen largos aleatorios o venta al peso, se facturará la '
                .'cantidad efectivamente entregada. El comprador deberá considerar que en '
                .'estos casos la cantidad podrá tener un exceso o una disminución respecto '
                .'de la cantidad cotizada.',
            ],
            [
                'Precios',
                'Los importes están expresados en Dólares Estadounidenses "billete", y se '
                .'entienden más IVA por material entregado en nuestras oficinas en la '
                .'Ciudad de Bs. As.',
            ],
            [
                'Entrega',
                $importacion
                    ? '120 días corridos a contar a partir del día hábil siguiente de la '
                        .'recepción y aceptación de la O/C, y/o de la acreditación del '
                        ."anticipo, lo que ocurra último.\n"
                        .'Solo en caso de aplicar requisitos normativos específicos '
                        .'(Reglamentos Técnicos) tales como la excepción a la Seguridad de '
                        .'Aceros, deberá adicionarse al plazo de entrega el tiempo que '
                        .'demore su tramitación y aprobación.'
                    : '2-4 días hábiles a contar a partir del día hábil siguiente de la '
                        .'recepción y aceptación de la O/C, y/o de la acreditación del '
                        .'anticipo, lo que ocurra último.',
            ],
            [
                'Certificación',
                'El material se entregará con certificado de calidad.',
            ],
            [
                'Condic. de Pago',
                $importacion
                    ? '50% de anticipo junto con el pedido. Saldo contado contra entrega. '
                        .'Otras formas de pago a convenir de común acuerdo. Sujeto a la '
                        .'recepción de referencias comerciales a nuestra satisfacción.'
                    : 'Contado contra entrega; para material que requiera corte, pago '
                        .'anticipado al corte. Otras formas de pago a convenir de común '
                        .'acuerdo. Sujeto a la recepción de referencias comerciales a '
                        .'nuestra satisfacción.',
            ],
            [
                'Forma de Pago',
                $importacion
                    ? 'Nuestras facturas se emiten en DOLARES ESTADOUNIDENSES, en un todo de '
                        .'acuerdo a la legislación vigente. Las mismas se pueden cancelar a '
                        ."través de los siguientes medios:\n"
                        .'• Pagos en PESOS: deberán cancelarse por los medios habituales '
                        .'utilizando el tipo de cambio publicado por el BNA Billete venta del '
                        .'cierre del día hábil inmediato anterior a la acreditación del pago. '
                        .'Todo pago en moneda distinta a Dólares Estadounidenses se recibirá a '
                        .'cuenta del importe en Dólares Estadounidenses consignado en la '
                        .'factura, con la condición de que toda fluctuación mayor al 1% en el '
                        .'tipo de cambio entre la fecha de emisión de la factura y el tipo de '
                        .'cambio aplicable a la fecha de acreditación del pago se ajustará '
                        .'automáticamente mediante N.C / N.D.'
                    : 'Nuestras facturas se emiten en DOLARES ESTADOUNIDENSES, en un todo de '
                        .'acuerdo a la legislación vigente. Las mismas podrán cancelarse en '
                        .'Pesos por los medios habituales utilizando el tipo de cambio '
                        .'publicado por el BNA Billete venta del cierre del día hábil '
                        .'inmediato anterior a la acreditación del pago. Todo pago en moneda '
                        .'distinta a Dólares Estadounidenses se recibirá a cuenta del importe '
                        .'en Dólares Estadounidenses consignado en la factura, con la '
                        .'condición de que toda fluctuación mayor al 1% en el tipo de cambio '
                        .'entre la fecha de emisión de la factura y el tipo de cambio '
                        .'aplicable a la fecha de acreditación del pago se ajustará '
                        .'automáticamente mediante N.C / N.D.',
            ],
            [
                // {vence} lo reemplaza la cotizacion con su propia fecha. No se
                // guarda una fecha fija: una plantilla con la fecha de otra
                // cotizacion es exactamente el error que hay que evitar.
                'Validez de Oferta',
                '{vence}, salvo venta previa y sujeto a disponibilidad. La modificación o '
                .'supresión de algún item o de las cantidades cotizadas harán necesaria una '
                .'nueva cotización. No válida para compras parciales. Disponibilidad y plazo '
                .'de entrega a confirmar al momento de realizar el pedido. Basado en las '
                .'condiciones cambiarias, arancelarias e impositivas vigentes a la fecha de '
                .'la cotización. La modificación de las mismas harán necesario un ajuste de '
                .'precios a la fecha de despacho a plaza, de facturación y/o de '
                .'efectivización del pago, según corresponda el caso, incluso para pedidos '
                .'en curso en los cuales se haya recibido un anticipo. Sujeto a la variación '
                .'del costo en origen.',
            ],
            [
                'IMPORTANTE',
                'Precios según las cotizaciones de los metales del día de hoy, revisables al '
                .'día del pedido.',
            ],
        ];
    }
}
