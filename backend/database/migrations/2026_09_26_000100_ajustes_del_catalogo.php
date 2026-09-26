<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
  Lo que pidio CORDES despues de revisar el sistema cargado.

  Son arreglos de datos, no de codigo: por eso van en una migracion y no a
  mano, asi el servidor queda igual que la maquina de desarrollo sin que nadie
  tenga que acordarse de repetirlos.

  Casi todos son el mismo defecto de origen: el Access guardaba texto libre
  donde tendria que haber una lista, y con treinta anios de carga a mano quedo
  "Buenos Aires" escrito de nueve formas y codigos postales en el campo pais.

  El down() no deshace, a proposito: volver a partir Buenos Aires en nueve no
  le sirve a nadie.
*/
return new class extends Migration
{
    /** Los nueve paises que existen de verdad en la cartera. */
    private const PAISES = [
        'argentina', 'bolivia', 'brasil', 'chile', 'colombia',
        'espana', 'mexico', 'paraguay', 'uruguay',
    ];

    /**
     * Como escribieron cada provincia, y cual es.
     *
     * Solo entran las que no se resuelven solas sacandoles los acentos y los
     * puntos. Lo que no esta en esta lista no se toca: preferimos un nombre
     * raro a una empresa mudada de provincia.
     */
    private const PROVINCIAS = [
        'bsas' => 'Buenos Aires',
        'bsaires' => 'Buenos Aires',
        'baires' => 'Buenos Aires',
        'bs' => 'Buenos Aires',
        'provbsas' => 'Buenos Aires',
        'capfed' => 'Ciudad Autónoma de Buenos Aires',
        'capital' => 'Ciudad Autónoma de Buenos Aires',
        'capitalfede' => 'Ciudad Autónoma de Buenos Aires',
        'capitalfederal' => 'Ciudad Autónoma de Buenos Aires',
        'caba' => 'Ciudad Autónoma de Buenos Aires',
        'sanafe' => 'Santa Fe',
        'santafed' => 'Santa Fe',
    ];

    public function up(): void
    {
        $this->medidasDeLaChapa();
        $this->unidadesRepetidas();
        $this->paisesQueEranCodigosPostales();
        $this->provinciasEscritasDeVariasFormas();
        $this->densidadDeLosInoxidables();
    }

    public function down(): void
    {
        // A proposito.
    }

    /** Sin acentos, sin puntos y sin espacios: "BS. AS." y "bsas" son lo mismo. */
    private function plano(string $texto): string
    {
        $sinAcentos = strtr(mb_strtolower($texto), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);

        return preg_replace('/[^a-z0-9]/', '', $sinAcentos) ?? '';
    }

    /**
     * La chapa se escribe espesor, ancho y largo.
     *
     * El formulario pedia ancho, espesor y largo, y el propio catalogo decia
     * "Espesor x ancho x largo" en medidas_habituales: se contradecian entre
     * si. CORDES confirmo cual es el bueno.
     *
     * Solo cambia el orden en que se muestran y se escriben. Las medidas ya
     * cargadas no se mueven: cada una vive en su columna —ancho_mm,
     * espesor_mm— y ahi se queda.
     */
    private function medidasDeLaChapa(): void
    {
        DB::table('formas')->whereIn('nombre', ['CHAPA', 'PLANCHUELA'])->update([
            'campos' => json_encode([
                ['clave' => 'height', 'label' => 'Espesor'],
                ['clave' => 'width', 'label' => 'Ancho'],
                ['clave' => 'length', 'label' => 'Largo'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Tres pares de unidades que son la misma cosa.
     *
     * "En unidad hay repetido (UN y C/U es lo mismo)". Habia dos pares mas que
     * no llegaron a ver: MT con M, y TN con TON.
     *
     * La que sobra no se borra, se desactiva: desaparece del desplegable y las
     * lineas que la usaban pasan a la que queda. El texto impreso de esas
     * lineas no se toca — sigue diciendo "C/U (+IVA)" como el dia que se
     * escribio.
     */
    private function unidadesRepetidas(): void
    {
        foreach (['UN' => 'C/U', 'MT' => 'M', 'TN' => 'TON'] as $queda => $seVa) {
            $idQueda = DB::table('unidades')->where('codigo', $queda)->value('id');
            $idSeVa = DB::table('unidades')->where('codigo', $seVa)->value('id');

            if ($idQueda === null || $idSeVa === null) {
                continue;
            }

            foreach (['unidad_venta_id', 'unidad_factura_id', 'unidad_pedida_id'] as $columna) {
                DB::table('consulta_lineas')->where($columna, $idSeVa)->update([$columna => $idQueda]);
            }

            DB::table('unidades')->where('id', $idSeVa)->update(['activo' => false]);
        }
    }

    /**
     * Once "paises" que en realidad eran un codigo postal.
     *
     * "campo paises tiene mucha basura": B1646gel, C1426aaa, X5176ead,
     * S2123aof no son basura al azar, son codigos postales argentinos (CPA)
     * cargados una columna corrida. Cada uno tiene exactamente una empresa
     * detras y casi todas ya tienen el CP en su campo; a las que no, se lo
     * devolvemos ahora. Tambien estan "Arg." y "Paraguaya", que son el mismo
     * pais escrito de otra forma.
     */
    private function paisesQueEranCodigosPostales(): void
    {
        $porNombre = DB::table('paises')->get(['id', 'nombre'])
            ->mapWithKeys(fn ($p) => [$this->plano($p->nombre) => $p->id]);

        $argentina = $porNombre['argentina'] ?? null;

        if ($argentina === null) {
            return;
        }

        $sobran = DB::table('paises')->get(['id', 'nombre'])
            ->reject(fn ($p) => in_array($this->plano($p->nombre), self::PAISES, true));

        foreach ($sobran as $pais) {
            $destino = $this->plano($pais->nombre) === 'paraguaya'
                ? ($porNombre['paraguay'] ?? $argentina)
                : $argentina;

            // Un CPA es una letra, cuatro numeros y tres letras: B1646GEL.
            if (preg_match('/^[a-z]\d{4}[a-z]{3}$/', $this->plano($pais->nombre))) {
                DB::table('empresas')
                    ->where('pais_id', $pais->id)
                    ->where(fn ($q) => $q->whereNull('codigo_postal')->orWhere('codigo_postal', ''))
                    ->update(['codigo_postal' => mb_strtoupper($pais->nombre)]);
            }

            DB::table('empresas')->where('pais_id', $pais->id)->update(['pais_id' => $destino]);

            /*
              Las provincias cuelgan del pais y no se pueden mudar de a una:
              hay una clave unica por (pais, nombre), y la SANTA FE que colgaba
              de "Arg." choca con la Santa Fe que ya estaba en Argentina.
            */
            foreach (DB::table('provincias')->where('pais_id', $pais->id)->get(['id', 'nombre']) as $provincia) {
                $yaEsta = DB::table('provincias')
                    ->where('pais_id', $destino)
                    ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($provincia->nombre)])
                    ->value('id');

                if ($yaEsta === null) {
                    DB::table('provincias')->where('id', $provincia->id)->update(['pais_id' => $destino]);

                    continue;
                }

                $this->fusionarProvincia($provincia->id, $yaEsta);
            }

            DB::table('paises')->where('id', $pais->id)->delete();
        }
    }

    /**
     * Buenos Aires estaba escrita de nueve formas.
     *
     * BS AS, BS AIRES, BS. AS., BS.AS, B AIRES, BS.AIRES, BS.AS., PROV BS AS
     * y Buenos Aires: 129 empresas repartidas en nueve filas distintas. Buscar
     * "las empresas de Buenos Aires" devolvia 113 de 129.
     *
     * Se junta todo en el nombre bien escrito. Lo que no se pueda reconocer
     * con seguridad queda como esta: una empresa con la provincia rara se
     * arregla a mano, una empresa mudada de provincia no se nota.
     */
    private function provinciasEscritasDeVariasFormas(): void
    {
        $provincias = DB::table('provincias')->orderBy('id')->get(['id', 'nombre', 'pais_id']);

        // La fila que se queda con cada nombre: la mejor escrita de todas.
        $buenas = [];

        foreach ($provincias as $p) {
            $clave = $p->pais_id.'|'.$this->plano($p->nombre);

            if (! isset($buenas[$clave]) || mb_strlen($p->nombre) > mb_strlen($buenas[$clave]->nombre)) {
                $buenas[$clave] = $p;
            }
        }

        foreach ($provincias as $p) {
            $plano = $this->plano($p->nombre);

            // "-", "Sin determinar" o un codigo postal no son una provincia.
            $esVacia = $plano === '' || $plano === 'sindeterminar'
                || (bool) preg_match('/^[a-z]\d{4}[a-z]{3}$/', $plano);

            if ($esVacia) {
                DB::table('empresas')->where('provincia_id', $p->id)->update(['provincia_id' => null]);
                $this->soltarProvincia($p->id);

                continue;
            }

            $destino = isset(self::PROVINCIAS[$plano])
                ? ($buenas[$p->pais_id.'|'.$this->plano(self::PROVINCIAS[$plano])] ?? null)
                : ($buenas[$p->pais_id.'|'.$plano] ?? null);

            if ($destino === null || $destino->id === $p->id) {
                continue;
            }

            $this->fusionarProvincia($p->id, $destino->id);
        }
    }

    /**
     * Pasa todo lo que cuelga de una provincia a otra y borra la que sobra.
     *
     * Las localidades tambien tienen clave unica por (provincia, nombre), asi
     * que hay que bajar un piso mas: si la localidad ya existe del otro lado,
     * se mudan las empresas y se borra la repetida.
     */
    private function fusionarProvincia(int $de, int $a): void
    {
        foreach (DB::table('localidades')->where('provincia_id', $de)->get(['id', 'nombre']) as $localidad) {
            $yaEsta = DB::table('localidades')
                ->where('provincia_id', $a)
                ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($localidad->nombre)])
                ->value('id');

            if ($yaEsta === null) {
                DB::table('localidades')->where('id', $localidad->id)->update(['provincia_id' => $a]);

                continue;
            }

            DB::table('empresas')->where('localidad_id', $localidad->id)->update(['localidad_id' => $yaEsta]);
            DB::table('localidades')->where('id', $localidad->id)->delete();
        }

        DB::table('empresas')->where('provincia_id', $de)->update(['provincia_id' => $a]);
        DB::table('razones_sociales')->where('iibb_provincia_sede_id', $de)->update(['iibb_provincia_sede_id' => $a]);
        DB::table('provincias')->where('id', $de)->delete();
    }

    /** Borra una provincia que no nombra nada, con todo lo que cuelga. */
    private function soltarProvincia(int $id): void
    {
        DB::table('empresas')
            ->whereIn('localidad_id', DB::table('localidades')->where('provincia_id', $id)->pluck('id'))
            ->update(['localidad_id' => null]);

        DB::table('localidades')->where('provincia_id', $id)->delete();
        DB::table('razones_sociales')->where('iibb_provincia_sede_id', $id)->update(['iibb_provincia_sede_id' => null]);
        DB::table('provincias')->where('id', $id)->delete();
    }

    /**
     * La densidad de los inoxidables, que CORDES ya definio.
     *
     * "Acero AISI 310S no tiene densidad cargada: no se calcula el peso". Del
     * catalogo que vino del Access, 1.165 materiales llegaron sin densidad.
     * Para los inoxidables y duplex la definicion ya existe desde el
     * 09-09-2026: 8,00 g/cm3 al cotizar (para inventario CORDES usa la real).
     *
     * Los demas —renio, grafito, tantalio— siguen sin densidad y la linea lo
     * avisa: ahi no hay definicion aprobada, y un numero inventado se
     * convierte en un peso facturado.
     */
    private function densidadDeLosInoxidables(): void
    {
        /*
          El filtro se hace en PHP y no en SQL: reconocer "AISI 310S" pide una
          expresion regular, y cada base escribe la suya. Son 1.249 materiales,
          no hay nada que optimizar.
        */
        $inoxidables = DB::table('materiales')
            ->whereNull('densidad')
            ->get(['id', 'nombre'])
            ->filter(fn ($m) => preg_match('/(?i)inox|duplex|dúplex|aisi\s*\d/u', (string) $m->nombre))
            ->pluck('id');

        if ($inoxidables->isNotEmpty()) {
            DB::table('materiales')->whereIn('id', $inoxidables)->update(['densidad' => 8.00]);
        }
    }
};
