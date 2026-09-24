<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $porTipo = fn (string $tipo) => $this->medios
            ->filter(fn ($m) => $m->tipoMedio?->nombre === $tipo && $m->activo)
            ->sortByDesc('principal')
            ->map(fn ($m) => ['id' => $m->id, 'valor' => $m->valor, 'principal' => $m->principal, 'nota' => $m->nota])
            ->values();

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'sector' => $this->sector,
            'cargo' => $this->cargo,
            'principal' => $this->principal,
            'activo' => $this->activo,
            'observacion' => $this->observacion,
            'telefonos' => $porTipo('Telefono'),
            'whatsapps' => $porTipo('WhatsApp'),
            'mails' => $porTipo('Mail'),
        ];
    }
}
