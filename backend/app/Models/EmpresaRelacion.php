<?php

namespace App\Models;

use App\Models\Concerns\RegistraCambios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmpresaRelacion extends Model
{
    use RegistraCambios;

    protected $table = 'empresa_relacion';

    protected $guarded = [];

    protected $casts = [
        'activa' => 'boolean',
        'desde' => 'date',
    ];

    /** Las cinco opciones. "Agenda general" es para las que no son ninguna de las comerciales. */
    public const OPCIONES = ['Cliente', 'Proveedor', 'Servicio', 'Empleado', 'Agenda general'];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
