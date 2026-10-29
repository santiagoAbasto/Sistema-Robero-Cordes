<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
  Las condiciones de cotizacion ya no dicen "mas IVA".

  Pedido del cliente (29-10-2026): ahora la hoja dice sola si el precio
  incluye o no el IVA, segun la condicion de la ficha ("Total (no incluye
  IVA)", "Total (IVA incluido)"). Las condiciones decian "mas IVA" para todos
  y a un consumidor final le contradecian el total.

  Solo se cambia el texto tal como lo cargo el sistema: si la empresa ya lo
  reescribio, queda como lo dejo. Las cotizaciones ya armadas guardaron su
  propio texto y no se tocan.
*/
return new class extends Migration
{
    private const CAMBIOS = [
        'y se entienden más IVA por material entregado' => 'y se entienden por material entregado',
        'Precios en Dolares Estadounidenses, mas IVA' => 'Precios en Dolares Estadounidenses',
    ];

    public function up(): void
    {
        $this->reemplazar(self::CAMBIOS);
    }

    public function down(): void
    {
        $this->reemplazar(array_flip(self::CAMBIOS));
    }

    private function reemplazar(array $cambios): void
    {
        foreach (DB::table('condiciones_habituales')->get(['id', 'texto']) as $condicion) {
            $nuevo = strtr((string) $condicion->texto, $cambios);

            if ($nuevo !== $condicion->texto) {
                DB::table('condiciones_habituales')->where('id', $condicion->id)->update(['texto' => $nuevo]);
            }
        }
    }
};
