<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qué cambió, quién y qué decía antes. Se anota solo.
 * No se puede editar ni borrar: si alguien se equivocó, se corrige el dato
 * y queda anotada también la corrección.
 */
class HistorialCambio extends Model
{
    protected $table = 'historial_cambios';

    protected $guarded = [];

    protected $casts = ['fecha' => 'datetime'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
