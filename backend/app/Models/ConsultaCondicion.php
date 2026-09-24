<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Las condiciones que sí salen impresas en la hoja del cliente. */
class ConsultaCondicion extends Model
{
    protected $table = 'consulta_condiciones';

    protected $guarded = [];

    protected $casts = ['imprime' => 'boolean'];

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }
}
