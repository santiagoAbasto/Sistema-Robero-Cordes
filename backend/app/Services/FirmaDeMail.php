<?php

namespace App\Services;

use App\Models\Localidad;
use App\Models\Pais;
use App\Models\Provincia;

/**
 * Saca los datos de una empresa del pie de un mail.
 *
 * "Agregar nuevo cliente: posibilidad de copiar algo —ejemplo pie de mail—
 * para que complete la informacion". Hoy hay que mirar el mail en una ventana
 * y tipear en la otra: nombre, cargo, telefono, direccion, localidad,
 * provincia. Doce campos a mano, con el dato delante de los ojos.
 *
 * Funciona SIN IA, con las mismas reglas que el lector de pedidos: lo que no
 * se reconoce con seguridad queda vacio. Todo lo que devuelve es una
 * propuesta que la persona revisa antes de guardar, porque un CUIT o un
 * codigo postal mal leido despues sale en una factura.
 */
class FirmaDeMail
{
    /** Las lineas de encabezado de un mail reenviado. */
    private const ENCABEZADOS = '/^\s*(from|de|sent|enviado|to|para|cc|cco|subject|asunto|fecha)\s*:/i';

    /**
     * Las etiquetas de la ficha de contacto que llega desde la web.
     *
     * ORIGEN esta para que se reconozca como etiqueta y no como dato: si no,
     * "CONSULTA DESDE LA WEB" terminaba siendo el nombre de alguien.
     */
    private const ETIQUETAS = [
        'empresa' => ['EMPRESA', 'RAZON SOCIAL', 'COMPAÑIA'],
        'contacto' => ['NOMBRE Y APELLIDO', 'APELLIDO Y NOMBRE', 'NOMBRE', 'CONTACTO'],
        'cargo' => ['CARGO', 'PUESTO'],
        'mail' => ['CORREO ELECTRONICO', 'E-MAIL', 'EMAIL', 'MAIL', 'CORREO'],
        // Separados: el rotulo dice que tipo de telefono es.
        'celular' => ['CELULAR', 'MOVIL', 'CEL'],
        'whatsapp' => ['WHATSAPP'],
        'telefono' => ['TELEFONO', 'TEL'],
        'direccion' => ['DIRECCION', 'DOMICILIO'],
        'codigo_postal' => ['CODIGO POSTAL', 'C.P.', 'CP'],
        'localidad' => ['LOCALIDAD', 'CIUDAD'],
        'provincia' => ['PROVINCIA'],
        'pais' => ['PAIS'],
        'origen' => ['ORIGEN'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function leer(string $texto): array
    {
        /*
          Primero, si es una ficha de la web.

          Se reconoce porque trae el nombre o la empresa con su etiqueta: una
          firma nunca escribe "Nombre:". Si se exigieran solo dos etiquetas
          cualquiera, una firma con "Tel:" y "Mail:" pasaria por ficha y se
          perderia el nombre de quien firma.
        */
        $ficha = CamposDeFormulario::leer($texto, self::ETIQUETAS)[0] ?? [];

        if (isset($ficha['contacto']) || isset($ficha['empresa'])) {
            return $this->deUnaFicha($ficha, $texto);
        }

        $renglones = array_values(array_filter(
            array_map('trim', preg_split('/[\r\n]+/', $texto) ?: []),
            fn ($r) => $r !== '',
        ));

        $mail = $this->mail($texto);

        $datos = [
            'empresa' => $this->empresa($texto, $renglones, $mail),
            'contacto' => $this->contacto($renglones),
            'cargo' => $this->cargo($renglones),
            'mail' => $mail,
            'telefono' => $this->telefono($texto)[0],
            'tipo_telefono' => $this->telefono($texto)[1],
            'web' => $this->web($texto, $mail),
            'direccion' => $this->direccion($renglones),
            'codigo_postal' => $this->codigoPostal($texto),
        ];

        return $datos + $this->deDonde($texto);
    }

    /**
     * Los datos de una ficha de la web: cada uno en su campo, tal cual vino.
     *
     * Lo que la ficha no trae no se busca con las reglas de la firma: esas
     * miran renglones sueltos y en una ficha se comen las etiquetas. Solo se
     * completa con lo que se reconoce por su forma —un mail, un telefono, un
     * codigo postal— que no se confunde con una etiqueta.
     *
     * @param  array<string, string>  $ficha
     * @return array<string, mixed>
     */
    private function deUnaFicha(array $ficha, string $texto): array
    {
        $mail = $ficha['mail'] ?? $this->mail($texto);

        $datos = [
            'empresa' => $ficha['empresa'] ?? null,
            'contacto' => $ficha['contacto'] ?? null,
            'cargo' => $ficha['cargo'] ?? null,
            'mail' => $mail,
            ...$this->telefonoDeLaFicha($ficha, $texto),
            'web' => $this->web($texto, $mail),
            'direccion' => $ficha['direccion'] ?? null,
            'codigo_postal' => $ficha['codigo_postal'] ?? $this->codigoPostal($texto),
        ];

        /*
          Donde queda: lo que dicen sus campos, no el texto entero. En el texto
          estan el nombre de la persona y el de la empresa, y cualquiera de los
          dos puede contener el nombre de una localidad.
        */
        $lugar = implode(' ', array_filter([
            $ficha['localidad'] ?? null, $ficha['provincia'] ?? null,
            $ficha['pais'] ?? null, $ficha['direccion'] ?? null,
        ]));

        return $datos + $this->deDonde($lugar);
    }

    /**
     * El telefono de la ficha y su tipo: el rotulo lo dice.
     *
     * @param  array<string, string>  $ficha
     * @return array{telefono: ?string, tipo_telefono: ?string}
     */
    private function telefonoDeLaFicha(array $ficha, string $texto): array
    {
        [$valor, $tipo] = match (true) {
            isset($ficha['celular']) => [$ficha['celular'], 'Celular'],
            isset($ficha['whatsapp']) => [$ficha['whatsapp'], 'WhatsApp'],
            isset($ficha['telefono']) => [
                $ficha['telefono'],
                $this->pareceCelular($ficha['telefono']) ? 'Celular' : 'Telefono',
            ],
            default => $this->telefono($texto),
        };

        return ['telefono' => $valor === null ? null : $this->limpiar($valor), 'tipo_telefono' => $tipo];
    }

    private function mail(string $texto): ?string
    {
        // El del remitente: el primero que aparece suele ser quien firma.
        preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $texto, $m);

        return $m[0] ?? null;
    }

    /**
     * El nombre de la empresa.
     *
     * Primero de la linea "From: Gonzalo Sack - Apex Metalurgica <...>", que
     * es donde suele estar escrito entero. Si no, del dominio del mail, que
     * nunca miente aunque venga abreviado.
     */
    private function empresa(string $texto, array $renglones, ?string $mail): ?string
    {
        foreach ($renglones as $r) {
            if (! preg_match('/^\s*(from|de)\s*:\s*(.+)$/i', $r, $m)) {
                continue;
            }

            // "Gonzalo Sack - Apex Metalurgica <gsack@apex.com.ar>"
            $sinMail = trim(preg_replace('/<[^>]*>/', '', $m[2]) ?? '');
            $partes = preg_split('/\s+[-–|]\s+/', $sinMail) ?: [];

            if (count($partes) > 1) {
                return trim(end($partes));
            }
        }

        /*
          El renglon que termina en su forma societaria: "Sulfoquimica S.A.".
          Es el nombre entero y legal; el dominio del mail es una abreviatura.
        */
        foreach ($renglones as $r) {
            if (! str_contains($r, '@') && mb_strlen($r) <= 60
                && preg_match('/\s(S\.?\s?A\.?(\s?I\.?\s?C\.?)?|S\.?\s?R\.?\s?L\.?|S\.?\s?A\.?\s?S\.?|LTDA\.?|INC\.?)$/iu', $r)) {
                return $r;
            }
        }

        if ($mail === null) {
            return null;
        }

        // De apex.com.ar sale Apex. Los dominios de correo de siempre no
        // dicen nada de la empresa.
        $dominio = mb_strtolower(explode('@', $mail)[1] ?? '');
        $genericos = ['gmail', 'hotmail', 'yahoo', 'outlook', 'live', 'icloud', 'speedy', 'fibertel'];
        $primera = explode('.', $dominio)[0] ?? '';

        return $primera !== '' && ! in_array($primera, $genericos, true)
            ? mb_convert_case($primera, MB_CASE_TITLE, 'UTF-8')
            : null;
    }

    /**
     * Quien firma.
     *
     * En una firma, el nombre es el renglon que son dos o tres palabras
     * capitalizadas, sin numeros, sin arroba y sin dos puntos.
     */
    private function contacto(array $renglones): ?string
    {
        foreach ($renglones as $r) {
            if (preg_match(self::ENCABEZADOS, $r) || str_contains($r, '@') || str_contains($r, ':')) {
                continue;
            }

            if (preg_match('/\d/', $r) || mb_strlen($r) > 48) {
                continue;
            }

            $palabras = preg_split('/\s+/', $r) ?: [];

            if (count($palabras) < 2 || count($palabras) > 4) {
                continue;
            }

            // Palabras capitalizadas o iniciales: "Juan J. Saccomanno". La
            // inicial es una sola letra y su punto: "S.A." no pasa.
            $capitalizadas = array_filter(
                $palabras,
                fn ($p) => preg_match('/^\p{Lu}(?:\p{L}+|\.)$/u', $p),
            );

            if (count($capitalizadas) === count($palabras)) {
                return $r;
            }
        }

        return null;
    }

    /** El cargo: el renglon siguiente al nombre suele serlo. */
    private function cargo(array $renglones): ?string
    {
        $nombre = $this->contacto($renglones);

        if ($nombre === null) {
            return null;
        }

        $i = array_search($nombre, $renglones, true);
        $siguiente = $renglones[$i + 1] ?? null;

        if ($siguiente === null || str_contains($siguiente, '@') || preg_match('/\d/', $siguiente)) {
            return null;
        }

        return mb_strlen($siguiente) <= 60 ? $siguiente : null;
    }

    /**
     * El telefono, con su prefijo.
     *
     * Se le exige una etiqueta —Cel, Tel, Movil— o el formato con parentesis:
     * en una firma hay codigos postales, numeros de calle y anios, y todos
     * son numeros.
     */
    /**
     * @return array{0: ?string, 1: ?string} el numero y su tipo de medio:
     *                                        Celular, WhatsApp o Telefono
     */
    private function telefono(string $texto): array
    {
        $conEtiqueta = '/(cel|tel|telefono|teléfono|movil|móvil|whatsapp|wpp)\.?\s*:?\s*'
            .'((?:\+?\d{1,3}[\s.-]*)?(?:\(\d{2,5}\)[\s.-]*)?[\d\s.-]{6,18})/iu';

        if (preg_match($conEtiqueta, $texto, $m)) {
            $rotulo = mb_strtolower($m[1]);

            $tipo = match (true) {
                in_array($rotulo, ['cel', 'movil', 'móvil'], true) => 'Celular',
                in_array($rotulo, ['whatsapp', 'wpp'], true) => 'WhatsApp',
                default => $this->pareceCelular($m[2]) ? 'Celular' : 'Telefono',
            };

            return [$this->limpiar($m[2]), $tipo];
        }

        // "(2954) 15-584584" se reconoce solo por la forma.
        if (preg_match('/\(\d{2,5}\)\s*[\d\s.-]{6,15}/', $texto, $m)) {
            return [$this->limpiar($m[0]), $this->pareceCelular($m[0]) ? 'Celular' : 'Telefono'];
        }

        return [null, null];
    }

    /**
     * Un celular escrito como se escriben aca: "(2954) 15-584584" —el 15
     * despues de la caracteristica— o con el 9 internacional, "+54 9 11".
     *
     * Solo se usa cuando el rotulo no lo dice. Sin 15 ni 9 no se sabe: un
     * 11 4555-3700 y un 11 3106-1795 tienen el mismo largo, y queda Telefono.
     */
    private function pareceCelular(string $numero): bool
    {
        return (bool) preg_match('/(?:^|[\s)(-])15[\s.-]?\d{3,4}[\s.-]?\d{2,4}\b|\+?54\s*9\s*\d/u', $numero);
    }

    private function limpiar(string $t): ?string
    {
        $t = trim(preg_replace('/\s+/', ' ', $t) ?? '');

        return $t === '' ? null : $t;
    }

    private function web(string $texto, ?string $mail): ?string
    {
        if (preg_match('#(?:https?://)?(?:www\.)[\w-]+(?:\.[\w-]+)+#i', $texto, $m)) {
            return mb_strtolower($m[0]);
        }

        return null;
    }

    /**
     * La direccion.
     *
     * El renglon que nombra una calle y tiene numero: "Parque Industrial,
     * Calle 9 esq. 10", "Av. Mitre 750". No se toma cualquier renglon con
     * numeros porque el telefono y el CP tambien los tienen.
     */
    private function direccion(array $renglones): ?string
    {
        $palabrasDeCalle = '/\b(av|avda|avenida|calle|ruta|camino|parque|km|kil[oó]metro|'
            .'colectora|pasaje|bv|boulevard|diagonal|manzana|mza|lote|piso|oficina|of)\b/iu';

        foreach ($renglones as $r) {
            if (str_contains($r, '@') || preg_match(self::ENCABEZADOS, $r)) {
                continue;
            }

            if (preg_match($palabrasDeCalle, $r) && preg_match('/\d/', $r)) {
                // El CP suele venir pegado con una barra: se corta ahi.
                $sinCp = preg_split('/\s*\|\s*/', $r)[0] ?? $r;

                return $this->limpiar(preg_replace('/\bCP\s*\d{4,8}\b/i', '', $sinCp) ?? $r);
            }
        }

        /*
          Sin palabra de calle, el nombre de la calle y su numero: "Panamá
          8051". Es como se escribe la mayoria de las direcciones de aca.

          ponytail: un renglon "Santa Rosa 6300" —localidad y CP sin rotular—
          tambien tiene esta forma y se tomaria por direccion. Pasa poco y la
          persona revisa antes de guardar; si molesta, descartar los renglones
          cuyo texto sea una localidad del catalogo.
        */
        foreach ($renglones as $r) {
            if (str_contains($r, '@') || str_contains($r, ':') || preg_match(self::ENCABEZADOS, $r)) {
                continue;
            }

            if (preg_match('/\b(cel|tel|movil|móvil|whatsapp|c\.?\s*p\.?)\b/iu', $r)) {
                continue;
            }

            // Letras, un espacio, y un numero de calle de hasta cinco cifras.
            if (preg_match('/^\p{L}[\p{L}\s.\'°º-]{2,40}\s\d{1,5}$/u', $r)) {
                return $r;
            }
        }

        return null;
    }

    /** "CP 6300" o un CPA argentino: B1646GEL. */
    private function codigoPostal(string $texto): ?string
    {
        // "CP 6300", "C.P. (1682)", "CP: 1406".
        if (preg_match('/\bC\.?\s?P\.?\s*:?\s*\(?\s*([A-Z]?\d{4}[A-Z]{0,3})\b/i', $texto, $m)) {
            return mb_strtoupper($m[1]);
        }

        if (preg_match('/\b([A-Z]\d{4}[A-Z]{3})\b/', $texto, $m)) {
            return mb_strtoupper($m[1]);
        }

        return null;
    }

    /**
     * Localidad, provincia y pais, buscados en el catalogo.
     *
     * No se inventa ninguno: se devuelve el id del que ya existe. Si la
     * localidad no esta cargada, el campo queda vacio y se elige a mano —
     * dar de alta una localidad desde el pie de un mail llenaria la tabla de
     * variantes mal escritas, que es justo lo que acabamos de limpiar.
     *
     * @return array<string, int|null>
     */
    private function deDonde(string $texto): array
    {
        $plano = fn (string $t) => preg_replace(
            '/[^a-z0-9]/', '',
            strtr(mb_strtolower($t), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']),
        ) ?? '';

        $enElTexto = $plano($texto);

        $buscar = function ($filas) use ($plano, $enElTexto) {
            // Gana el nombre mas largo: "Santa Rosa" antes que "Rosa".
            $mejor = null;
            $largo = 0;

            foreach ($filas as $fila) {
                $clave = $plano($fila->nombre);

                if (mb_strlen($clave) >= 4 && mb_strlen($clave) > $largo && str_contains($enElTexto, $clave)) {
                    [$mejor, $largo] = [$fila->id, mb_strlen($clave)];
                }
            }

            return $mejor;
        };

        $localidades = Localidad::query()->get(['id', 'nombre', 'provincia_id']);
        $provinciaId = $buscar(Provincia::query()->get(['id', 'nombre']));
        $localidadId = $buscar($localidades);

        /*
          La localidad tiene que ser de la provincia que se encontro.

          "Martin Coronado C.P. (1682) - Prov. Buenos Aires": Martin Coronado
          no esta cargada, y "Buenos Aires" —el nombre de la provincia— si
          esta como localidad, pero de la Ciudad Autonoma. Salia una
          localidad de CABA con provincia de Buenos Aires, que se contradice
          sola. Una localidad vacia se elige a mano; una equivocada no se ve.
        */
        if ($localidadId !== null && $provinciaId !== null
            && $localidades->firstWhere('id', $localidadId)?->provincia_id !== $provinciaId) {
            $localidadId = null;
        }

        return [
            'pais_id' => $buscar(Pais::query()->get(['id', 'nombre'])),
            'provincia_id' => $provinciaId,
            'localidad_id' => $localidadId,
        ];
    }
}
