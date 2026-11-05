<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una condición de trabajo que se puede cargar en la ficha: "Tipo de pago", "Flete",
 * "Cómo lo retira". Es la lista cerrada de los TÍTULOS: la ficha ya no acepta uno escrito a mano.
 */
class TipoDeCondicion extends Model
{
    protected $table = 'tipos_de_condicion';

    protected $guarded = [];

    protected $casts = [
        'activo' => 'boolean',
        'proponer_al_cotizar' => 'boolean',
    ];

    public function opciones(): HasMany
    {
        return $this->hasMany(OpcionDeCondicion::class, 'tipo_de_condicion_id')->orderBy('orden')->orderBy('texto');
    }

    public function campos(): HasMany
    {
        return $this->hasMany(EmpresaCampo::class, 'tipo_de_condicion_id');
    }
}
