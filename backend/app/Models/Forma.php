<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Una forma geometrica: barra redonda, caño, chapa.
 *
 * Cada forma sabe que medidas pide y con que cuenta se saca su volumen. Eso
 * esta guardado como dato, no escrito en el codigo: se puede agregar una forma
 * nueva o corregir una cuenta sin tocar el sistema.
 */
class Forma extends Model
{
    protected $table = 'formas';

    protected $guarded = [];

    protected $casts = [
        'activo' => 'boolean',
        'usa_cano' => 'boolean',
        'campos' => 'array',
    ];

    /**
     * Una forma sin clave no puede existir, asi que se la pone sola.
     *
     * Evita que cada lugar que crea formas —seeders, importaciones, la
     * pantalla— tenga que acordarse de generarla.
     */
    protected static function booted(): void
    {
        static::creating(function (self $forma) {
            $forma->clave ??= static::claveDesde($forma->nombre ?? 'forma');
        });
    }

    /**
     * Las medidas que hay que cargar para esta forma.
     *
     * @return list<array{clave: string, label: string}>
     */
    public function camposDelCalculo(): array
    {
        return collect($this->campos ?? [])
            ->filter(fn ($c) => filled($c['clave'] ?? null))
            ->map(fn ($c) => ['clave' => $c['clave'], 'label' => $c['label'] ?? $c['clave']])
            ->values()
            ->all();
    }

    /**
     * En que orden se escriben las medidas de esta forma.
     *
     * No es el mismo orden que el del formulario, y esa es la trampa. El
     * formulario de una chapa pide ancho, espesor y largo; escrita, una chapa
     * es "2 X 1000 X 2000" y ese 2 es el espesor. Leerla en el orden de los
     * campos carga 2 mm de ancho y 1000 de espesor, y de ahi sale el peso que
     * se factura.
     *
     * El orden sale de medidas_habituales, que es como el catalogo dice que
     * se escribe esa forma: "Espesor x ancho x largo". Se emparejan sus
     * pedazos con los campos por el nombre. Asi lo decide el catalogo —y lo
     * puede corregir cualquiera desde Formas y formulas— y no una lista
     * escrita aca adentro.
     *
     * Si no se entiende, queda el orden de los campos: es una propuesta que
     * alguien revisa, no un dato que se guarda solo.
     *
     * @return list<string> claves de campos, en el orden en que se escriben
     */
    public function ordenEnQueSeEscriben(): array
    {
        $campos = $this->camposDelCalculo();
        $claves = array_column($campos, 'clave');
        $pistas = trim((string) $this->medidas_habituales);

        if ($pistas === '' || count($campos) < 2) {
            return $claves;
        }

        $pedazos = preg_split('/\s*[x×+]\s*/iu', $pistas) ?: [];

        // Tiene que nombrar exactamente las medidas que la forma pide. "Ø" o
        // "Diametro nominal + norma" no dicen el orden de nada.
        if (count($pedazos) !== count($campos)) {
            return $claves;
        }

        $orden = [];
        $libres = $campos;

        foreach ($pedazos as $pedazo) {
            $pedazo = mb_strtolower(trim($pedazo));
            $mejor = null;
            $puntajeMejor = 0;

            foreach ($libres as $i => $campo) {
                $puntaje = 0;

                foreach (preg_split('/\s+/', mb_strtolower($campo['label'])) as $palabra) {
                    // Por el principio de la palabra: "espesor" tiene que
                    // encontrar "Espesor de pared".
                    if (mb_strlen($palabra) > 3 && str_contains($pedazo, mb_substr($palabra, 0, 5))) {
                        $puntaje += mb_strlen($palabra);
                    }
                }

                if ($puntaje > $puntajeMejor) {
                    [$mejor, $puntajeMejor] = [$i, $puntaje];
                }
            }

            if ($mejor === null) {
                return $claves;
            }

            $orden[] = $libres[$mejor]['clave'];
            unset($libres[$mejor]);
        }

        return $orden;
    }

    public function tieneCalculoAutomatico(): bool
    {
        return filled($this->expresion);
    }

    /**
     * "BARRA REDONDA" -> "barra_redonda".
     *
     * Si la clave ya está tomada le agrega un sufijo: dos formas no pueden
     * compartirla, es la que las identifica.
     */
    public static function claveDesde(string $nombre): string
    {
        $base = Str::snake(Str::ascii(mb_strtolower($nombre)));
        $base = trim(preg_replace('/[^a-z0-9_]+/', '_', $base) ?? '', '_') ?: 'forma';

        return static::where('clave', $base)->exists() ? $base.'_'.Str::random(4) : $base;
    }
}
