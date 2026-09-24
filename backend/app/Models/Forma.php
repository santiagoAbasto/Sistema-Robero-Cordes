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
