<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un dato que puede tener una condición: "A cargo del cliente", "30 días fecha factura". */
class OpcionDeCondicion extends Model
{
    protected $table = 'opciones_de_condicion';

    protected $guarded = [];

    protected $casts = ['activo' => 'boolean'];

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoDeCondicion::class, 'tipo_de_condicion_id');
    }

    public function campos(): HasMany
    {
        return $this->hasMany(EmpresaCampo::class, 'opcion_de_condicion_id');
    }
}
