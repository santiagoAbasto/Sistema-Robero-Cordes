<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** La fila de la lista de empresas: lo justo para elegir y entrar a la ficha. */
class EmpresaListaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $principal = $this->contactos->firstWhere('principal', true) ?? $this->contactos->first();
        $habitual = $this->razonesSociales->firstWhere('habitual', true);
        $otrasRazones = max(0, $this->razonesSociales->count() - 1);

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'codigo_indice' => $this->codigo_indice,
            'codigo_isis' => $this->codigo_isis,
            'cuit' => $this->cuit,
            'relaciones' => $this->relaciones->where('activa', true)->pluck('relacion')->values(),
            'localidad' => $this->localidad?->nombre,
            'provincia' => $this->provincia?->nombre,
            'rubro' => $this->rubro?->nombre,
            'contacto_principal' => $principal ? [
                'nombre' => $principal->nombre,
                'sector' => $principal->sector,
            ] : null,
            'otros_contactos' => max(0, $this->contactos->count() - 1),
            'tambien_factura_como' => $otrasRazones > 0
                ? ($this->razonesSociales->where('habitual', false)->first()?->razon_social)
                : null,
            'otras_razones' => max(0, $otrasRazones - 1),
            'razon_social_habitual' => $habitual?->razon_social,
            'cotizaciones' => $this->consultas_count ?? 0,
            'activa' => $this->activa,
        ];
    }
}
