<?php

namespace Database\Seeders;

use App\Models\CondicionHabitual;
use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Localidad;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Moneda;
use App\Models\Pais;
use App\Models\Provincia;
use App\Models\Rubro;
use App\Models\TipoMedio;
use App\Models\Unidad;
use Illuminate\Database\Seeder;

class CatalogosSeeder extends Seeder
{
    /** La moneda con la que arranca toda cotizacion nueva. */
    public const MONEDA_POR_DEFECTO = 'DOLAR BILLETE BNA VENDEDOR';

    public function run(): void
    {
        // ------------------------------------------------------------ geografía
        $argentina = Pais::firstOrCreate(['nombre' => 'Argentina']);
        foreach (['Brasil', 'Chile', 'Uruguay'] as $p) {
            Pais::firstOrCreate(['nombre' => $p]);
        }

        // Las 24 jurisdicciones. Van escritas igual que las devuelve Google, para
        // que los predictivos de direccion las encuentren solas.
        //
        // Las localidades son las que mas se usan: la lista no puede estar
        // completa. Cuando una direccion trae una que no esta, el sistema deja
        // el campo en blanco y avisa, no la inventa.
        $provincias = [
            'Ciudad Autónoma de Buenos Aires' => ['C.A.B.A.'],
            'Buenos Aires' => ['La Plata', 'San Martin', 'Campana', 'Zarate', 'Avellaneda',
                'Quilmes', 'Tigre', 'Pilar', 'Mar del Plata', 'Bahia Blanca'],
            'Catamarca' => ['S.F. del Valle de Catamarca', 'Andalgala'],
            'Chaco' => ['Resistencia'],
            'Chubut' => ['Comodoro Rivadavia', 'Puerto Madryn', 'Trelew'],
            'Córdoba' => ['Cordoba', 'Rio Tercero', 'Villa Maria', 'Rio Cuarto'],
            'Corrientes' => ['Corrientes'],
            'Entre Ríos' => ['Parana', 'Concordia'],
            'Formosa' => ['Formosa'],
            'Jujuy' => ['San Salvador de Jujuy', 'Palpala'],
            'La Pampa' => ['Santa Rosa'],
            'La Rioja' => ['La Rioja'],
            'Mendoza' => ['Mendoza', 'Lujan de Cuyo', 'San Rafael'],
            'Misiones' => ['Posadas'],
            'Neuquén' => ['Neuquen', 'Añelo', 'Plaza Huincul'],
            'Río Negro' => ['Cipolletti', 'General Roca', 'S.C. de Bariloche'],
            'Salta' => ['Salta'],
            'San Juan' => ['San Juan'],
            'San Luis' => ['San Luis', 'Villa Mercedes'],
            'Santa Cruz' => ['Rio Gallegos', 'Caleta Olivia'],
            'Santa Fe' => ['Rosario', 'Santa Fe', 'San Lorenzo', 'Villa Constitucion', 'Rafaela'],
            'Santiago del Estero' => ['Santiago del Estero'],
            'Tierra del Fuego' => ['Ushuaia', 'Rio Grande'],
            'Tucumán' => ['S.M. de Tucuman'],
        ];

        $idProvincia = [];

        foreach (array_keys($provincias) as $nombreProv) {
            $prov = Provincia::firstOrCreate(
                ['pais_id' => $argentina->id, 'nombre' => $nombreProv]
            );

            // Las primeras seis se habian cargado sin acento. La base las empareja
            // igual, pero se guardan bien escritas porque se muestran en pantalla.
            if ($prov->nombre !== $nombreProv) {
                $prov->update(['nombre' => $nombreProv]);
            }

            $idProvincia[$nombreProv] = $prov->id;
        }

        // C.A.B.A. estaba colgada de Buenos Aires. Es su propia jurisdiccion, asi
        // que se la mueve; las empresas que la tenian siguen apuntando a la misma.
        $this->unificarCaba($idProvincia['Ciudad Autónoma de Buenos Aires']);

        foreach ($provincias as $nombreProv => $localidades) {
            foreach ($localidades as $loc) {
                Localidad::firstOrCreate(['provincia_id' => $idProvincia[$nombreProv], 'nombre' => $loc]);
            }
        }

        // --------------------------------------------------------------- rubros
        foreach (['TITANIO NIMO', 'ACEROS', 'MINERIA', 'PETROQUIMICA', 'ALIMENTICIA', 'AUTOPARTES'] as $r) {
            Rubro::firstOrCreate(['nombre' => $r]);
        }

        // --------------------------------------------------------------- formas
        $formas = [
            'BARRA REDONDA' => 'Diametro x largo',
            'BARRA' => 'Diametro x largo',
            'BARRA HEXAGONAL' => 'Entre caras x largo',
            'BARRA CUADRADA' => 'Lado x largo',
            'CAÑO' => 'Diametro nominal + SCH + largo',
            'TUBO' => 'Diametro exterior x espesor x largo',
            'CHAPA' => 'Espesor x ancho x largo',
            'PLANCHUELA' => 'Espesor x ancho x largo',
            'ALAMBRE' => 'Diametro x metros o kilos',
            'DISCO' => 'Diametro x espesor',
            'ANILLO' => 'Diametro exterior x interior x espesor',
            'BRIDA' => 'Diametro nominal + norma',
            'PERFIL' => 'Medida del perfil x largo',
        ];

        foreach ($formas as $nombre => $medidas) {
            Forma::firstOrCreate(['nombre' => $nombre], [
                // La clave es el nombre corto y estable con el que se
                // referencia la forma; el nombre visible puede cambiar.
                'clave' => Forma::claveDesde($nombre),
                'medidas_habituales' => $medidas,
            ]);
        }

        // ----------------------------------------------------------- materiales
        // La densidad es en g/cm3: con eso y el diámetro el sistema propone
        // los kilos por metro cuando se cotiza por metro y se factura por kilo.
        $materiales = [
            ['HASTELLOY C-276', 'Aleaciones de niquel', 8.89, ['HASTELLOY C-276', 'HAST C276', 'HASTELLOY C276', 'HAST. C-276']],
            ['HASTELLOY C-22', 'Aleaciones de niquel', 8.69, ['HASTELLOY C-22', 'HAST C22']],
            ['MONEL 400', 'Aleaciones de niquel', 8.80, ['MONEL', 'MONEL 400']],
            ['INCONEL 600', 'Aleaciones de niquel', 8.47, ['INCONEL 600', 'INC 600']],
            ['INCONEL 625', 'Aleaciones de niquel', 8.44, ['INCONEL 625', 'INC 625']],
            ['NIQUEL 200', 'Niquel', 8.89, ['NIQUEL 200', 'NI 200']],
            ['NIQUEL 201', 'Niquel', 8.89, ['NIQUEL 201', 'NI 201']],
            ['TITANIO GR2', 'Titanio', 4.51, ['TITANIO GR2', 'TIT GR2', 'TI GR 2']],
            ['TITANIO GR5', 'Titanio', 4.43, ['TITANIO GR5', 'TI GR 5']],
            ['AISI 304', 'Inoxidables', 8.00, ['AISI 304', 'INOX 304']],
            ['AISI 316', 'Inoxidables', 8.00, ['AISI 316', 'INOX 316']],
            ['AISI 316TI', 'Inoxidables', 8.00, ['AISI 316TI', '316 TI']],
            // 8,00 para todos los inoxidables y duplex: es lo que CORDES usa
            // para cotizar (confirmado 09-09-2026). El inventario ajusta la
            // densidad real por su lado, pero eso es otro sistema.
            ['DUPLEX 2205', 'Inoxidables', 8.00, ['DUPLEX 2205', 'UNS S32205']],
            ['25-22-2', 'Inoxidables', 8.00, ['25-22-2', '25.22.2', 'UNS S31050', 'CRNIMO 25.22.2']],
        ];

        $barra = Forma::where('nombre', 'BARRA REDONDA')->first();

        foreach ($materiales as [$nombre, $familia, $densidad, $alias]) {
            // updateOrCreate y no firstOrCreate: cuando la empresa confirma una
            // densidad, correrlo de nuevo tiene que alcanzar para actualizarla.
            // Lo ya cotizado no se toca: cada linea guarda su propia foto.
            $mat = Material::updateOrCreate(
                ['nombre' => $nombre],
                ['familia' => $familia, 'densidad' => $densidad]
            );

            $mat->forma_habitual_id ??= $barra?->id;
            $mat->save();

            foreach ($alias as $a) {
                MaterialAlias::firstOrCreate(['material_id' => $mat->id, 'alias' => $a]);
            }
        }

        // ------------------------------------------------------------- unidades
        $unidades = [
            ['UN', 'Unidades sueltas'],
            ['C/U', 'Cada uno'],
            ['MT', 'Metros'],
            ['KG', 'Kilos'],
            ['TN', 'Toneladas'],
        ];

        foreach ($unidades as [$codigo, $nombre]) {
            Unidad::firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre]);
        }

        // -------------------------------------------------------------- monedas
        // No alcanza con la moneda: hay que saber contra qué referencia
        // se toma el tipo de cambio.
        $monedas = [
            ['PESOS', 'Peso argentino', null, false],
            ['DOLAR LIBRE', 'Dolar', 'Mercado libre', true],
            ['DOLAR BILLETE BNA VENDEDOR', 'Dolar', 'Banco Nacion, billete, tipo vendedor', true],
            ['DOLAR DIVISA BNA VENDEDOR', 'Dolar', 'Banco Nacion, divisa, tipo vendedor', true],
            ['EURO LIBRE', 'Euro', 'Mercado libre', true],
            ['EURO BILLETE BNA VENDEDOR', 'Euro', 'Banco Nacion, billete, tipo vendedor', true],
            ['EURO DIVISA BNA VENDEDOR', 'Euro', 'Banco Nacion, divisa, tipo vendedor', true],
        ];

        foreach ($monedas as [$nombre, $base, $ref, $conv]) {
            Moneda::updateOrCreate(
                ['nombre' => $nombre],
                [
                    'moneda_base' => $base,
                    'referencia' => $ref,
                    'lleva_conversion' => $conv,
                    // La que viene puesta en cada cotizacion nueva. Es la que
                    // usan las ofertas de la empresa: los precios van en
                    // dolares "billete" y el pago se convierte al BNA Billete
                    // venta. Se puede cambiar en cualquier cotizacion.
                    'por_defecto' => $nombre === self::MONEDA_POR_DEFECTO,
                ]
            );
        }

        // ------------------------------------------------------- medios y otros
        foreach (['Telefono', 'WhatsApp', 'Mail'] as $t) {
            TipoMedio::firstOrCreate(['nombre' => $t]);
        }

        $condiciones = [
            'Plazo de entrega: segun disponibilidad',
            'Precios en Dolares Estadounidenses, mas IVA',
            'Mercaderia sujeta a venta previa',
        ];

        foreach ($condiciones as $i => $texto) {
            CondicionHabitual::firstOrCreate(['texto' => $texto], ['orden' => $i + 1]);
        }
    }

    /**
     * Deja una sola C.A.B.A., colgada de su propia jurisdiccion.
     *
     * Antes estaba como localidad de Buenos Aires. Las empresas que apuntaban a
     * la vieja se pasan a la que queda, para no perder la localidad de nadie.
     */
    private function unificarCaba(int $idCaba): void
    {
        $correcta = Localidad::firstOrCreate(['provincia_id' => $idCaba, 'nombre' => 'C.A.B.A.']);

        $repetidas = Localidad::where('nombre', 'C.A.B.A.')
            ->where('id', '!=', $correcta->id)
            ->get();

        foreach ($repetidas as $vieja) {
            Empresa::where('localidad_id', $vieja->id)->update(['localidad_id' => $correcta->id]);
            $vieja->delete();
        }
    }
}
