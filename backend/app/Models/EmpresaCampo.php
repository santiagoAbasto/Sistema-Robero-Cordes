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
}
