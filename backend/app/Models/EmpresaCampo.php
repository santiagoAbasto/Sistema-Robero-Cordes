<?php

namespace App\Models;

use App\Models\Concerns\RegistraCambios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmpresaCampo extends Model
{
    use RegistraCambios;

    protected $table = 'empresa_campos';

    protected $guarded = [];

    protected $casts = ['usar_al_cotizar' => 'boolean'];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** La condición de la lista. Los campos viejos, de antes de la lista, todavía no la tienen. */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoDeCondicion::class, 'tipo_de_condicion_id');
    }

    /** El dato de la lista. Sin él, el dato está "fuera de la lista" (se escribió a mano). */
    public function opcion(): BelongsTo
    {
        return $this->belongsTo(OpcionDeCondicion::class, 'opcion_de_condicion_id');
    }
}
