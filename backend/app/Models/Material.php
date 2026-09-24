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
