<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
  La condicion frente al IVA y la condicion de pago habitual, en la ficha.

  Pedido del cliente (29-10-2026). El IVA estaba solo en las razones
  sociales, y muchas fichas no tienen ninguna cargada; segun esta condicion
  la hoja sale con los precios mas IVA o con el IVA incluido. La condicion de
  pago se traia de un dato suelto llamado "Tipo de pago" que nadie veia:
  ahora es un campo de la ficha y la cotizacion nueva la trae sola.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $tabla) {
            $tabla->string('condicion_iva', 30)->nullable();
            $tabla->string('condicion_pago', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $tabla) {
            $tabla->dropColumn(['condicion_iva', 'condicion_pago']);
        });
    }
};
