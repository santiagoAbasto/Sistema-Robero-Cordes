<?php

namespace App\Models;

use App\Models\Concerns\RegistraCambios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Web, redes y mapa de la empresa. Se cargan una vez y quedan clickeables. */
class EmpresaEnlace extends Model
{
    use RegistraCambios;

    protected $table = 'empresa_enlaces';

    protected $guarded = [];

    public const TIPOS = ['Web', 'Instagram', 'Facebook', 'LinkedIn', 'YouTube', 'WhatsApp', 'Mapa', 'Otro'];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** Si pegaron el dominio sin http, igual tiene que abrir. */
    public function getUrlCompletaAttribute(): string
    {
        $url = trim($this->url);

        return str_starts_with($url, 'http') ? $url : 'https://'.$url;
    }
}
