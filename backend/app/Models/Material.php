<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    protected $table = 'materiales';

    protected $guarded = [];

    protected $casts = [
        'activo' => 'boolean',
        'densidad' => 'decimal:4',
    ];

    /**
     * Como deja marcado el catalogo un material que no se tiene que usar: "Acero Duplex 2507 NO USAR",
     * "Acero 13CrMo45 (usar 44)", "zzNo Acero ASTM A516 Gr.60", "zPENTOXIDO DE NIOBIO", "borrar Acero Al-6XN".
     * La "z" suelta va con mayuscula detras (no es Zinc ni Zafiro) y las "zz" valen en cualquier caso.
     */
    private const NO_SE_USA = '/^\s*zz|(?-i:^z[A-Z])|\busar\b|\bno\s*cargar\b|\bborrar\b|[\s-]no!?\s*$/iu';

    public static function noSeUsa(string $nombre): bool
    {
        return (bool) preg_match(self::NO_SE_USA, $nombre);
    }

    /** @return list<int> los materiales marcados para no usarse */
    public static function idsQueNoSeUsan(): array
    {
        return static::query()->get(['id', 'nombre'])
            ->filter(fn (self $m) => static::noSeUsa($m->nombre))
            ->pluck('id')->all();
    }

    public function alias(): HasMany
    {
        return $this->hasMany(MaterialAlias::class);
    }

    public function formaHabitual(): BelongsTo
    {
        return $this->belongsTo(Forma::class, 'forma_habitual_id');
    }

    /**
     * Busca por nombre o por cualquiera de las formas en que lo escriben:
     * "hast c276" tiene que encontrar HASTELLOY C-276.
     */
    public function scopeBuscar($query, ?string $texto)
    {
        if (! $texto) {
            return $query;
        }

        return $query->where(function ($q) use ($texto) {
            $q->where('nombre', 'like', "%{$texto}%")
                ->orWhereHas('alias', fn ($a) => $a->where('alias', 'like', "%{$texto}%"));
        });
    }
}
