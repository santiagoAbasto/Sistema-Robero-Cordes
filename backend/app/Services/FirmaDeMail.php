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

        $empresa = $this->empresa($texto, $renglones, $mail);
        $cargo = $this->cargo($renglones);

        $datos = [
            'empresa' => $empresa,
            'contacto' => $this->contacto($renglones),
            // "Gerente Comercial, JMH SRL": la empresa ya tiene su campo.
            'cargo' => $cargo === null || $empresa === null ? $cargo
                : preg_replace('/\s*[,|]\s*'.preg_quote($empresa, '/').'$/u', '', $cargo),
            'mail' => $mail,
            // Todos los telefonos, no solo el primero: una firma suele traer el
            // fijo y el celular. El primero queda como el principal.
            'telefono' => ($tels = $this->telefonos($texto))[0]['valor'] ?? null,
            'tipo_telefono' => $tels[0]['tipo'] ?? null,
            'telefonos' => $tels,
            'web' => $this->web($texto, $mail),
            'enlaces' => $this->enlaces($texto),
            'direccion' => $calle = $this->direccion($renglones),
            // El renglon entero, con la ciudad: para ubicarla con Google
            // cuando la localidad no esta en la lista. No es un dato mas.
            'direccion_completa' => $calle === null ? null
                : collect($renglones)->first(fn ($r) => str_contains($r, $calle)),
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
            'telefono' => ($tels = $this->telefonosDeLaFicha($ficha, $texto))[0]['valor'] ?? null,
            'tipo_telefono' => $tels[0]['tipo'] ?? null,
            'telefonos' => $tels,
            'web' => $this->web($texto, $mail),
            'enlaces' => $this->enlaces($texto),
            'direccion' => $this->soloLaCalle($ficha['direccion'] ?? null),
            'direccion_completa' => $ficha['direccion'] ?? null,
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
     * Los telefonos de la ficha: cada campo rotulado (celular, whatsapp, tel) y
     * lo que haya suelto en el texto, sin repetir.
     *
     * @param  array<string, string>  $ficha
     * @return list<array{valor: string, tipo: string}>
     */
    private function telefonosDeLaFicha(array $ficha, string $texto): array
    {
        $lista = [];

        if (isset($ficha['celular'])) {
            $this->sumarTelefono($lista, $ficha['celular'], 'Celular');
        }
        if (isset($ficha['whatsapp'])) {
            $this->sumarTelefono($lista, $ficha['whatsapp'], 'WhatsApp');
        }
        if (isset($ficha['telefono'])) {
            $this->sumarTelefono($lista, $ficha['telefono'],
                $this->pareceCelular($ficha['telefono']) ? 'Celular' : 'Telefono');
        }

        foreach ($this->telefonos($texto) as $t) {
            $clave = preg_replace('/\D+/', '', $t['valor']) ?? '';
            $lista[$clave] ??= $t;
        }

        return array_values($lista);
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
     * Primero de la linea "From: Pablo Ferreyra - Apex Metalurgica <...>", que
     * es donde suele estar escrito entero. Si no, del dominio del mail, que
     * nunca miente aunque venga abreviado.
     */
    private function empresa(string $texto, array $renglones, ?string $mail): ?string
    {
        foreach ($renglones as $r) {
            if (! preg_match('/^\s*(from|de)\s*:\s*(.+)$/i', $r, $m)) {
                continue;
            }

            // "Pablo Ferreyra - Apex Metalurgica <pferreyra@apex.com.ar>"
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
                // "Gerente Comercial, JMH SRL": lo de antes de la coma es el cargo.
                return preg_replace('/^.*[,|]\s*(?=\S+\s)/u', '', $r);
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

            // Palabras capitalizadas o iniciales: "Pedro A. Quiroga". La
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
     * Todos los telefonos del texto, cada uno con su tipo y ya normalizado.
     *
     * Antes se devolvia solo el primero y "Tel: X / Cel: Y" perdia el celular.
     * Se le exige una etiqueta —Cel, Tel, Movil, Fax— o el formato con
     * parentesis: en una firma hay codigos postales, numeros de calle y anios,
     * y todos son numeros.
     *
     * @return list<array{valor: string, tipo: string}>
     */
    public function telefonos(string $texto): array
    {
        $lista = [];

        // Las etiquetas enteras antes que las abreviaturas —"celular" antes que
        // "cel"— para que "Celular:" se reconozca entero y no como "Cel" + "ular".
        // "Tel / Fax:" tambien: la barra y el "Fax" quedan en el medio.
        $conEtiqueta = '/(whatsapp|wpp|celular|cel|tel[eé]fono|tel|m[oó]vil|fax)[\s\/.]*(?:fax)?[\s.:]*'
            .'((?:\+?\d{1,3}[\s.-]*)?(?:\(\d{2,5}\)[\s.-]*)?\d[\d\s.()+-]{5,18}(?:\s*(?:int|interno)\.?\s*\d{1,6})?)/iu';

        if (preg_match_all($conEtiqueta, $texto, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) {
                $rotulo = mb_strtolower($m[1]);

                $tipo = match (true) {
                    str_starts_with($rotulo, 'cel'), in_array($rotulo, ['movil', 'móvil'], true) => 'Celular',
                    str_starts_with($rotulo, 'what'), $rotulo === 'wpp' => 'WhatsApp',
                    default => $this->pareceCelular($m[2]) ? 'Celular' : 'Telefono',
                };

                $this->sumarTelefono($lista, $m[2], $tipo);
            }
        }

        // "(2954) 15-412233" se reconoce solo por la forma.
        if (preg_match_all('/\(\d{2,5}\)\s*[\d\s.-]{6,15}/', $texto, $ms)) {
            foreach ($ms[0] as $num) {
                $this->sumarTelefono($lista, $num, $this->pareceCelular($num) ? 'Celular' : 'Telefono');
            }
        }

        return array_values($lista);
    }

    /**
     * Suma un telefono a la lista, normalizado, sin repetir el mismo numero.
     *
     * @param  array<string, array{valor: string, tipo: string}>  $lista
     */
    private function sumarTelefono(array &$lista, string $raw, string $tipo): void
    {
        $valor = $this->normalizarTelefono($raw, $tipo);

        if ($valor === null) {
            return;
        }

        // Se compara por las cifras: "+54 9 11 ..." y "11 15 ..." son el mismo.
        $clave = preg_replace('/\D+/', '', $valor) ?? '';

        if ($clave !== '' && ! isset($lista[$clave])) {
            $lista[$clave] = ['valor' => $valor, 'tipo' => $tipo];
        }
    }

    /**
     * Un telefono argentino, siempre en el mismo formato: "+54 11 4699-7003" o
     * "+54 9 11 5009-3040" el celular. Unifica lo que llega mezclado —con 0054,
     * con 0 de larga distancia, con el 15, con +54 9— que es lo que pidio
     * Roberto: que no queden unos "+54 911" y otros "119342".
     *
     * Lo que no termina en un numero nacional de 10 cifras no se reformatea: se
     * deja como vino, limpio, porque inventar cifras es peor que un formato
     * disparejo. La persona revisa antes de guardar.
     */
    public function normalizarTelefono(?string $raw, ?string $tipo = null): ?string
    {
        if ($raw === null) {
            return null;
        }

        // La extension interna va aparte, al final: "int 109", "interno 5".
        $ext = null;
        if (preg_match('/\b(?:int|interno)\.?\s*(\d{1,6})\b/i', $raw, $m)) {
            $ext = $m[1];
            $raw = preg_replace('/\b(?:int|interno)\.?\s*\d{1,6}\b/i', '', $raw) ?? $raw;
        }

        $esMovil = in_array($tipo, ['Celular', 'WhatsApp'], true) || $this->pareceCelular($raw);

        $d = preg_replace('/\D+/', '', $raw) ?? '';
        $d = preg_replace('/^(?:00)?54/', '', $d) ?? $d;   // 0054, +54, 54
        $d = preg_replace('/^0/', '', $d) ?? $d;           // 0 de larga distancia (011)

        if ($esMovil && strlen($d) >= 11 && $d[0] === '9') {
            $d = substr($d, 1);                        // el 9 del celular internacional
        }
        if ($esMovil && strlen($d) === 12) {           // el 15, tras la caracteristica (2 a 4 cifras)
            foreach ([2, 3, 4] as $area) {
                if (substr($d, $area, 2) === '15') {
                    $d = substr($d, 0, $area).substr($d, $area + 2);
                    break;
                }
            }
        }

        if (strlen($d) !== 10) {
            $limpio = $this->limpiar($raw);

            return $limpio === null ? null : $limpio.($ext ? " int {$ext}" : '');
        }

        // "11" (CABA/GBA) son dos cifras de caracteristica; el resto se agrupa
        // parejo. El numero local lleva un guion antes de las ultimas cuatro.
        $nacional = str_starts_with($d, '11')
            ? '11 '.substr($d, 2, 4).'-'.substr($d, 6)
            : substr($d, 0, -4).'-'.substr($d, -4);

        return '+54 '.($esMovil ? '9 ' : '').$nacional.($ext ? " int {$ext}" : '');
    }

    /**
     * Un celular escrito como se escriben aca: "(2954) 15-412233" —el 15
     * despues de la caracteristica— o con el 9 internacional, "+54 9 11".
     *
     * Solo se usa cuando el rotulo no lo dice. Sin 15 ni 9 no se sabe: un
     * 11 4555-3700 y un 11 4567-2389 tienen el mismo largo, y queda Telefono.
     */
    public function pareceCelular(string $numero): bool
    {
        // "(+54-9) 11-6734-1149" tambien: el 9 puede venir con guion y parentesis.
        return (bool) preg_match('/(?:^|[\s)(-])15[\s.-]?\d{3,4}[\s.-]?\d{2,4}\b|(?<!\d)\+?54[\s.-]*9[\s.)-]*\d/u', $numero);
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
     * La web y las redes, para guardarlas como enlaces de la ficha.
     *
     * Las que estan escritas como direccion ("www.jmh.com.ar",
     * "linkedin.com/company/jmh-srl") y las que vienen con su rotulo y un
     * usuario ("Instagram: jmh.argentina"). El dominio del mail no cuenta:
     * es de donde escribe, no una pagina que hayan puesto.
     *
     * @return list<array{tipo: string, url: string}>
     */
    private function enlaces(string $texto): array
    {
        $redes = [
            'Instagram' => 'instagram.com', 'Facebook' => 'facebook.com',
            'LinkedIn' => 'linkedin.com', 'YouTube' => 'youtube.com',
        ];
        $enlaces = [];

        foreach ($redes as $tipo => $dominio) {
            // Escrita como direccion: "linkedin.com/company/jmh-srl".
            if (preg_match('#(?:https?://)?(?:www\.)?'.preg_quote($dominio, '#').'/[\w@./%-]+#iu', $texto, $m)) {
                $enlaces[$tipo] = rtrim($m[0], '.');
            // Con su rotulo y el usuario: "Instagram: jmh.argentina", "IG @jmh".
            } elseif (preg_match('/^\s*'.$tipo.'\s*:?\s*@?([\w.]{2,60})\s*$/imu', $texto, $m)) {
                $enlaces[$tipo] = $dominio.'/'.rtrim($m[1], '.');
            }
        }

        $webs = [];

        // Las paginas: con www, con http, o con el rotulo "Web:".
        preg_match_all('#(?:https?://)?www\.[\w-]+(?:\.[\w-]+)+|https?://[\w-]+(?:\.[\w-]+)+#iu', $texto, $m);
        $webs = $m[0];

        if (preg_match_all('/^\s*(?:web|sitio(?:\s+web)?|p[aá]gina)\s*:\s*((?:https?:\/\/)?[\w-]+(?:\.[\w-]+)+)\s*$/imu', $texto, $r)) {
            array_push($webs, ...$r[1]);
        }

        $resultado = [];

        foreach (array_unique(array_map('mb_strtolower', $webs)) as $web) {
            $sinWww = preg_replace('#^(?:https?://)?(?:www\.)?#', '', $web);

            // Una red escrita con www ya esta como red; no se repite como web.
            if (! collect($redes)->contains(fn ($d) => str_starts_with($sinWww, $d))
                && ! collect($resultado)->contains(fn ($e) => preg_replace('#^(?:https?://)?(?:www\.)?#', '', $e['url']) === $sinWww)) {
                $resultado[] = ['tipo' => 'Web', 'url' => $web];
            }
        }

        foreach ($enlaces as $tipo => $url) {
            $resultado[] = ['tipo' => $tipo, 'url' => mb_strtolower($url)];
        }

        return $resultado;
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

        // "Madariaga 830, Sarandí, Buenos Aires": despues del numero va el lugar.
        $renglones = array_map(fn ($r) => $this->soloLaCalle($r), $renglones);

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

    /**
     * La calle y el numero, sin lo que sigue a la coma: "General Deheza 3146,
     * Remedios de Escalada, Buenos Aires" es "General Deheza 3146". La
     * localidad y la provincia tienen su campo, y deDonde() las busca en el
     * texto entero. "Parque Industrial, Calle 9 esq. 10" no se toca: la coma
     * va antes del numero.
     */
    public function soloLaCalle(?string $direccion): ?string
    {
        return $direccion === null ? null : preg_replace('/(\d)\s*,.*$/u', '$1', $direccion);
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
        /*
          La localidad se busca entre las de la provincia que se encontro.

          "Martin Coronado - Prov. Buenos Aires": "Buenos Aires" esta como
          localidad de CABA y salia con provincia de Buenos Aires. Y en
          "Sarandí, Buenos Aires" esa localidad de CABA le ganaba a Sarandí por
          ser mas larga, se descartaba, y Sarandí se perdia.
        */
        $localidadId = $buscar($provinciaId ? $localidades->where('provincia_id', $provinciaId) : $localidades);

        /*
          Lo que falta se completa hacia arriba: la localidad dice su provincia
          y la provincia su pais. Casi ninguna firma de aca escribe
          "Argentina", y el pais quedaba vacio.
        */
        $provinciaId ??= $localidades->firstWhere('id', $localidadId)?->provincia_id;

        return [
            'pais_id' => $buscar(Pais::query()->get(['id', 'nombre']))
                ?? ($provinciaId ? Provincia::whereKey($provinciaId)->value('pais_id') : null),
            'provincia_id' => $provinciaId,
            'localidad_id' => $localidadId,
        ];
    }
}
