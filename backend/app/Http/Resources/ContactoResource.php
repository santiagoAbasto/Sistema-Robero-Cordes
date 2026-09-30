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
            'celulares' => $porTipo('Celular'),
            'whatsapps' => $porTipo('WhatsApp'),
            'mails' => $porTipo('Mail'),

            /*
              Todos los medios, de cualquier tipo, para poder editarlos.

              El modal los armaba con los tres grupos de arriba y al guardar se
              reemplazan todos: el fax —128 contactos lo tienen— no llegaba al
              modal, y abrir un contacto y guardarlo lo borraba sin aviso. Con
              la lista entera, un tipo nuevo no se pierde por no tener grupo.
            */
            'medios' => $this->medios
                ->filter(fn ($m) => $m->activo)
                ->sortByDesc('principal')
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'tipo_medio_id' => $m->tipo_medio_id,
                    'tipo' => $m->tipoMedio?->nombre,
                    'valor' => $m->valor,
                    'principal' => (bool) $m->principal,
                    'nota' => $m->nota,
                ])
                ->values(),
        ];
    }
}
