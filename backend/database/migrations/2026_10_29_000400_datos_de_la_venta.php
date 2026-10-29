<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
  Lo que se va sabiendo de una cotizacion vendida, en el orden en que pasa.

  Pedido del cliente (29-10-2026): llega el pedido y se carga en su Access,
  que le da un numero de pedido interno; despues sale la mercaderia con un
  remito; cuando el cliente da la conformidad se factura, con el tipo de
  cambio de esa factura. Se van completando de a uno. La factura ya tenia
  columna (nro_factura).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->string('nro_pedido_interno', 20)->nullable();
            $tabla->string('nro_remito', 30)->nullable();
            $tabla->decimal('tipo_cambio_factura', 14, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->dropColumn(['nro_pedido_interno', 'nro_remito', 'tipo_cambio_factura']);
        });
    }
};
