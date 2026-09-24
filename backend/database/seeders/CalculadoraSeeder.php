<?php

namespace Database\Seeders;

use App\Models\CanoEstandar;
use App\Models\Forma;
use App\Models\Material;
use Illuminate\Database\Seeder;

/**
 * Las cuentas de la calculadora de peso.
 *
 * Cada forma queda con las medidas que pide y la cuenta que las convierte en
 * volumen. Las medidas entran en milimetros y la cuenta devuelve cm3; el peso
 * sale despues, multiplicando por la densidad del material.
 *
 * Las claves de los campos (diameter, length, outer, wall…) son las mismas que
 * usan las formulas. Si se agrega una forma, la formula tiene que nombrar sus
 * campos igual.
 */
class CalculadoraSeeder extends Seeder
{
    public function run(): void
    {
        $this->formas();
        $this->canos();
        $this->designaciones();
    }

    private function formas(): void
    {
        $diametro = ['clave' => 'diameter', 'label' => 'Diametro'];
        $largo = ['clave' => 'length', 'label' => 'Largo'];
        $espesor = ['clave' => 'height', 'label' => 'Espesor'];
        $ancho = ['clave' => 'width', 'label' => 'Ancho'];
        $entreCaras = ['clave' => 'across', 'label' => 'Distancia entre caras'];

        $barraRedonda = [
            'campos' => [$diametro, $largo],
            'expresion' => '(pi * pow(diameter, 2) / 4 * length) / 1000',
        ];

        $chapa = [
            'campos' => [$ancho, $espesor, $largo],
            'expresion' => '(width * height * length) / 1000',
        ];

        $formas = [
            'BARRA REDONDA' => $barraRedonda,
            // "BARRA" a secas queda definida para que las cotizaciones viejas
            // se sigan abriendo, pero se desactiva mas abajo: CORDES confirmo
            // que no es una denominacion que usen.
            'BARRA' => $barraRedonda,
            'ALAMBRE' => $barraRedonda,

            'BARRA CUADRADA' => [
                'campos' => [['clave' => 'side', 'label' => 'Lado'], $largo],
                'expresion' => '(pow(side, 2) * length) / 1000',
            ],

            'BARRA RECTANGULAR' => [
                'campos' => [$ancho, $espesor, $largo],
                'expresion' => '(width * height * length) / 1000',
            ],

            'BARRA HEXAGONAL' => [
                'campos' => [$entreCaras, $largo],
                'expresion' => '(1.5 * 0.57735 * pow(across, 2) * length) / 1000',
            ],

            'BARRA OCTOGONAL' => [
                'campos' => [$entreCaras, $largo],
                'expresion' => '(2 * 0.41421356 * pow(across, 2) * length) / 1000',
            ],

            'CHAPA' => $chapa,
            'PLANCHUELA' => $chapa,

            'DISCO' => [
                'campos' => [$diametro, $espesor],
                'expresion' => '(pi * pow(diameter, 2) / 4 * height) / 1000',
            ],

            // Se calcula como anillo pero se cotiza como arandela: la
            // denominacion comercial es la que ve el cliente en la hoja.
            // Confirmado por CORDES el 09-09-2026.
            'ARANDELA' => [
                'campos' => [
                    ['clave' => 'outer', 'label' => 'Diametro exterior'],
                    ['clave' => 'inner', 'label' => 'Diametro interior'],
                    $espesor,
                ],
                'expresion' => '(pi * (pow(outer, 2) - pow(inner, 2)) / 4 * height) / 1000',
            ],

            'ANILLO' => [
                'campos' => [
                    ['clave' => 'outer', 'label' => 'Diametro exterior'],
                    ['clave' => 'inner', 'label' => 'Diametro interior'],
                    $espesor,
                ],
                'expresion' => '(pi * (pow(outer, 2) - pow(inner, 2)) / 4 * height) / 1000',
            ],

            // Tubo: se carga la medida. Caño: se elige de la tabla de comerciales.
            'TUBO' => [
                'campos' => [
                    ['clave' => 'outer', 'label' => 'Diametro exterior'],
                    ['clave' => 'wall', 'label' => 'Espesor de pared'],
                    $largo,
                ],
                'expresion' => '(pi * wall * (outer - wall) * length) / 1000',
            ],

            // El caño lleva las mismas medidas que el tubo y ademas la lista de
            // comerciales. Elegir uno de la lista completa el diametro y la
            // pared; si el caño no es de medida —que en materiales especiales
            // pasa seguido— se cargan a mano y listo.
            'CAÑO' => [
                'campos' => [
                    ['clave' => 'outer', 'label' => 'Diametro exterior'],
                    ['clave' => 'wall', 'label' => 'Espesor de pared'],
                    $largo,
                ],
                'expresion' => '(pi * wall * (outer - wall) * length) / 1000',
                'usa_cano' => true,
            ],

            'ESFERA' => [
                'campos' => [$diametro],
                'expresion' => '(pi * pow(diameter, 3) / 6) / 1000',
            ],
        ];

        foreach ($formas as $nombre => $datos) {
            $forma = Forma::firstOrNew(['nombre' => $nombre]);

            // La clave se pone solo al crear, y va explicita: los seeders corren
            // con WithoutModelEvents, asi que el gancho del modelo que la
            // completa NO se ejecuta acá. Una columna obligatoria no puede
            // depender de un evento.
            //
            // Y no se toca la de una forma que ya existe: es lo que la
            // identifica, y las cotizaciones viejas apuntan a ella.
            $forma->clave ??= Forma::claveDesde($nombre);

            $forma->fill(array_merge(['usa_cano' => false, 'activo' => true], $datos));
            $forma->save();
        }

        // BRIDA y PERFIL quedan sin formula a proposito: no son un solido
        // simple y el peso depende del plano. Se cargan a mano.

        $this->jubilarBarraSola();
        $this->jubilarNombresViejos();
    }

    /**
     * Los caños con el nombre viejo salen de la lista.
     *
     * La primera carga los llamaba 1.1/4" y la tabla de CORDES los llama
     * 1 1/4". Son el mismo caño con dos nombres, y dejar los dos hace que
     * alguien elija el equivocado. Se desactivan, no se borran: una cotizacion
     * vieja puede estar apuntando a ellos.
     */
    private function jubilarNombresViejos(): void
    {
        CanoEstandar::where('nombre', 'like', '%.%/%')->update(['activo' => false]);
    }

    /**
     * "BARRA" a secas sale de la lista.
     *
     * Estaba como sinonimo de BARRA REDONDA y CORDES confirmo el 09-09-2026 que
     * no es una denominacion que usen: "Barra sola debe desaparecer".
     *
     * Se desactiva, no se borra. Las cotizaciones viejas que la nombran tienen
     * que poder abrirse e imprimirse igual que el dia que se hicieron; lo unico
     * que cambia es que deja de ofrecerse al cotizar.
     */
    private function jubilarBarraSola(): void
    {
        Forma::where('nombre', 'BARRA')->update(['activo' => false]);
    }

    /**
     * Caños comerciales segun ANSI/ASME B36.10M y B36.19M.
     *
     * Los 17 schedules de la tabla que mando CORDES: 10, 20, 30, STD, 40, 60,
     * XS, 80, 100, 120, 140, 160 y XXS del B36.10, y 5S, 10S, 40S y 80S del
     * B36.19 (inoxidables). Antes habia solo 40 y 80.
     */
    private function canos(): void
    {
        // La tabla completa de ANSI/ASME B36.10M y B36.19M, tal como la mando
        // CORDES. Son 324 combinaciones de medida y schedule; antes habia 28.
        // El archivo trae ademas el kg/m impreso, que no se guarda: sirve de
        // control y lo verifica CanosEstandarTest.
        $tabla = require database_path('seeders/datos/canos_ansi.php');

        $orden = 0;

        foreach ($tabla as $nombre => [$exterior, $schedules]) {
            foreach ($schedules as $schedule => [$pared, $kgm]) {
                CanoEstandar::updateOrCreate(
                    ['nombre' => $nombre, 'schedule' => (string) $schedule],
                    [
                        'diametro_mm' => $exterior,
                        'pared_mm' => $pared,
                        'orden' => $orden++,
                        'activo' => true,
                    ],
                );
            }
        }
    }

    /** UNS y W.Nr.: como se pide el mismo material en cada norma. */
    private function designaciones(): void
    {
        $designaciones = [
            'AISI 304' => ['S30400', '1.4301'],
            'AISI 316' => ['S31600', '1.4401'],
            'AISI 316TI' => ['S31635', '1.4571'],
            'DUPLEX 2205' => ['S32205', '1.4462'],
            // El W.Nr. no lo tenemos confirmado: va vacio hasta que lo pasen.
            '25-22-2' => ['S31050', null],
            'HASTELLOY C-276' => ['N10276', '2.4819'],
            'HASTELLOY C-22' => ['N06022', '2.4602'],
            'MONEL 400' => ['N04400', '2.4360'],
            'INCONEL 600' => ['N06600', '2.4816'],
            'INCONEL 625' => ['N06625', '2.4856'],
            'NIQUEL 200' => ['N02200', '2.4066'],
            'NIQUEL 201' => ['N02201', '2.4068'],
            'TITANIO GR2' => ['R50400', '3.7035'],
            'TITANIO GR5' => ['R56400', '3.7165'],
        ];

        /*
          Se busca por nombre O por alias.

          Al traer el catalogo del sistema anterior manda el nombre de la
          empresa —"Acero AISI 304" y no "AISI 304"— y el nuestro queda de
          alias. Buscando solo por nombre, este update dejo de encontrar a los
          catorce y las designaciones UNS y W.Nr se perdieron en silencio: el
          seeder corria verde y no escribia nada.

          Por alias siempre los encuentra, se llamen como se llamen.
        */
        foreach ($designaciones as $nombre => [$uns, $wNr]) {
            $material = Material::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->first()
                ?? Material::whereHas(
                    'alias',
                    fn ($a) => $a->whereRaw('LOWER(alias) = ?', [mb_strtolower($nombre)])
                )->first();

            $material?->update(['uns' => $uns, 'w_nr' => $wNr]);
        }
    }
}
