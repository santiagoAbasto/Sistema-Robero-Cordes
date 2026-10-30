<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
  Cada dato de la venta con su fecha, y un comentario del pedido (pedido del
  cliente, 30-10-2026).

  Ya estaban los numeros (pedido interno, remito, factura) pero no cuando paso
  cada cosa. Roberto: "necesitariamos fecha para el pedido, fecha para el
  remito y fecha para la factura". La del remito y la factura suelen coincidir,
  pero no siempre —para algunas empresas primero se hace el remito y despues la
  factura—, asi que son tres fechas aparte, no una sola.

  Y un comentario del pedido, para anotar a quien se encargo y con que N° de
  orden: a diferencia del hilo de observaciones —que es historico y no se
  edita—, este se modifica y se actualiza, y siempre esta a la vista en la
  venta. Por eso es una columna propia y no otra observacion.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->date('fecha_pedido')->nullable()->after('nro_pedido_interno');
            $tabla->date('fecha_remito')->nullable()->after('nro_remito');
            $tabla->date('fecha_factura')->nullable()->after('nro_factura');
            $tabla->text('comentario_venta')->nullable()->after('tipo_cambio_factura');
        });
    }

    public function down(): void
    {
        Schema::table('consultas', function (Blueprint $tabla) {
            $tabla->dropColumn(['fecha_pedido', 'fecha_remito', 'fecha_factura', 'comentario_venta']);
        });
    }
};
