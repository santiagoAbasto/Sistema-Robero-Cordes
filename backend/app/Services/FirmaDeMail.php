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
     * @return array<string, mixed>
     */
    public function leer(string $texto): array
    {
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
            'telefono' => $this->telefono($texto),
            'web' => $this->web($texto, $mail),
            'direccion' => $this->direccion($renglones),
            'codigo_postal' => $this->codigoPostal($texto),
        ];

        return $datos + $this->deDonde($texto);
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

            $capitalizadas = array_filter(
                $palabras,
                fn ($p) => preg_match('/^\p{Lu}\p{L}+$/u', $p),
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
    private function telefono(string $texto): ?string
    {
        $conEtiqueta = '/(?:cel|tel|telefono|teléfono|movil|móvil|whatsapp|wpp)\.?\s*:?\s*'
            .'((?:\+?\d{1,3}[\s.-]*)?(?:\(\d{2,5}\)[\s.-]*)?[\d\s.-]{6,18})/iu';

        if (preg_match($conEtiqueta, $texto, $m)) {
            return $this->limpiar($m[1]);
        }

        // "(2954) 15-584584" se reconoce solo por la forma.
        if (preg_match('/\(\d{2,5}\)\s*[\d\s.-]{6,15}/', $texto, $m)) {
            return $this->limpiar($m[0]);
        }

        return null;
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

        return null;
    }

    /** "CP 6300" o un CPA argentino: B1646GEL. */
    private function codigoPostal(string $texto): ?string
    {
        if (preg_match('/\bCP\.?\s*([A-Z]?\d{4}[A-Z]{0,3})\b/i', $texto, $m)) {
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

        return [
            'pais_id' => $buscar(Pais::query()->get(['id', 'nombre'])),
            'provincia_id' => $buscar(Provincia::query()->get(['id', 'nombre'])),
            'localidad_id' => $buscar(Localidad::query()->get(['id', 'nombre'])),
        ];
    }
}
