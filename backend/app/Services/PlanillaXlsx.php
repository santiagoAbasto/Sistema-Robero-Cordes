<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Una planilla .xlsx de una sola hoja, escrita y leida sin libreria.
 *
 * Un .xlsx es un zip con XML adentro. Para bajar y subir las formas alcanza
 * con una hoja de texto y numeros, y eso son estas pocas lineas en vez de
 * una dependencia entera.
 *
 * ponytail: lee solo la primera hoja, texto, numeros, verdadero/falso y la
 * formula escrita (sin calcularla); fechas, celdas combinadas o varias hojas
 * no. Si un dia hace falta, PhpSpreadsheet.
 */
class PlanillaXlsx
{
    /** Lo maximo que se descomprime de una parte del archivo: frena un zip trampa. */
    private const MAXIMO = 20 * 1024 * 1024;

    /**
     * @param  list<list<string|int|float|null>>  $filas  la primera es la de los titulos
     * @return string el contenido del .xlsx
     */
    public function escribir(array $filas, string $hoja = 'Hoja1'): string
    {
        $xml = fn ($t) => htmlspecialchars((string) $t, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $renglones = '';
        foreach (array_values($filas) as $i => $fila) {
            $celdas = '';
            foreach (array_values($fila) as $j => $valor) {
                $ref = $this->columna($j).($i + 1);
                $estilo = $i === 0 ? ' s="1"' : '';

                $celdas .= is_int($valor) || is_float($valor)
                    ? "<c r=\"{$ref}\"{$estilo}><v>{$valor}</v></c>"
                    : "<c r=\"{$ref}\"{$estilo} t=\"inlineStr\"><is><t xml:space=\"preserve\">{$xml($valor)}</t></is></c>";
            }
            $renglones .= '<row r="'.($i + 1).'">'.$celdas.'</row>';
        }

        $partes = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.$xml($hoja).'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            // Dos estilos: el normal y el de los titulos, en negrita.
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
                .'<borders count="1"><border/></borders>'
                .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
                .'<cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs>'
                .'</styleSheet>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetData>'.$renglones.'</sheetData></worksheet>',
        ];

        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);
        foreach ($partes as $nombre => $contenido) {
            $zip->addFromString($nombre, $contenido);
        }
        $zip->close();

        $bytes = (string) file_get_contents($ruta);
        @unlink($ruta);

        return $bytes;
    }

    /**
     * Las filas de la primera hoja, cada celda como texto.
     *
     * Una celda con formula devuelve la formula escrita ("=PI()*D^2"), no su
     * resultado: en la planilla de formas la formula es el dato.
     *
     * @return list<list<string>>
     */
    public function leer(string $ruta): array
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('No es un archivo de Excel (.xlsx).');
        }

        try {
            $compartidos = [];
            if (($sst = $this->parte($zip, 'xl/sharedStrings.xml')) !== null) {
                foreach ($sst->si as $si) {
                    $compartidos[] = $this->texto($si);
                }
            }

            $hoja = $this->parte($zip, $this->primeraHoja($zip))
                ?? throw new RuntimeException('El archivo no tiene ninguna hoja.');

            $filas = [];
            foreach ($hoja->sheetData->row as $row) {
                $fila = [];
                foreach ($row->c as $c) {
                    $tipo = (string) $c['t'];
                    $valor = match (true) {
                        isset($c->f) && (string) $c->f !== '' => '='.$c->f,
                        $tipo === 's' => $compartidos[(int) $c->v] ?? '',
                        $tipo === 'inlineStr' => $this->texto($c->is),
                        $tipo === 'b' => ((string) $c->v) === '1' ? 'SI' : 'NO',
                        default => (string) $c->v,
                    };
                    $fila[$this->indice((string) $c['r']) ?? count($fila)] = trim($valor);
                }
                $filas[] = $fila === [] ? [] : array_replace(array_fill(0, max(array_keys($fila)) + 1, ''), $fila);
            }

            return $filas;
        } finally {
            $zip->close();
        }
    }

    private function primeraHoja(ZipArchive $zip): string
    {
        $libro = $this->parte($zip, 'xl/workbook.xml');
        $rels = $this->parte($zip, 'xl/_rels/workbook.xml.rels');
        $id = $libro?->sheets->sheet[0]?->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];

        foreach ($rels?->Relationship ?? [] as $rel) {
            if ((string) $rel['Id'] === (string) $id) {
                return 'xl/'.ltrim(preg_replace('#^/?xl/#', '', (string) $rel['Target']), '/');
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function parte(ZipArchive $zip, string $nombre): ?\SimpleXMLElement
    {
        $info = $zip->statName($nombre);

        if ($info === false) {
            return null;
        }

        if ($info['size'] > self::MAXIMO) {
            throw new RuntimeException('El archivo es demasiado grande.');
        }

        // Sin LIBXML_NOENT: las entidades externas no se resuelven.
        $xml = simplexml_load_string((string) $zip->getFromName($nombre), \SimpleXMLElement::class, LIBXML_NONET);

        return $xml === false ? null : $xml;
    }

    /** El texto de una celda, aunque venga partido en tramos con formato. */
    private function texto(\SimpleXMLElement $nodo): string
    {
        if (isset($nodo->t)) {
            return (string) $nodo->t;
        }

        return implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($nodo->r, false)));
    }

    /** "C7" -> 2. */
    private function indice(string $ref): ?int
    {
        if (! preg_match('/^([A-Z]+)\d+$/', $ref, $m)) {
            return null;
        }

        $n = 0;
        foreach (str_split($m[1]) as $letra) {
            $n = $n * 26 + (ord($letra) - 64);
        }

        return $n - 1;
    }

    /** 2 -> "C". */
    private function columna(int $i): string
    {
        $letras = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $letras = chr(65 + ($i - 1) % 26).$letras;
        }

        return $letras;
    }
}
