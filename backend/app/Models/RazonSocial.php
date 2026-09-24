<?php

namespace App\Models;

use App\Models\Concerns\RegistraCambios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RazonSocial extends Model
{
    use RegistraCambios;

    protected $table = 'razones_sociales';

    protected $guarded = [];

    protected $casts = [
        'habitual' => 'boolean',
        'activa' => 'boolean',
        'inicio_actividades' => 'date',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function provinciaSede(): BelongsTo
    {
        return $this->belongsTo(Provincia::class, 'iibb_provincia_sede_id');
    }
}
