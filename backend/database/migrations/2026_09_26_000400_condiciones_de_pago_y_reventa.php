<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
  Dos puntos del repaso de CORDES.

  13 · "Condicion de pago: no hay cargado, no deja agregar". La tabla estaba
       vacia: el desplegable no ofrecia nada y no habia por donde sumar una.
       Se siembran las formas de pago de siempre, y de ahora en mas la que se
       escriba a mano queda guardada para la proxima.

  7 · "Condiciones: ¿debieramos agregar una tercera opcion REVENTA?". Si, y
      queda habilitada. Va vacia a proposito: los ocho parrafos de Importacion
      y los de Stock los escribio la empresa, y los de Reventa los tiene que
      escribir la empresa tambien. Un parrafo comercial inventado se imprime y
      se le manda a un cliente.
*/
return new class extends Migration
{
    /**
     * Las formas de pago que se usan en el rubro.
     *
     * No son de CORDES en particular: son las que existen. Se pueden borrar,
     * renombrar o sumar desde la misma cotizacion.
     */
    private const PAGOS = [
        'Contado contra entrega',
        'Anticipo 50% - Saldo contra entrega',
        'Anticipo 100%',
        '30 dias fecha factura',
        '60 dias fecha factura',
        '90 dias fecha factura',
        '30/60 dias',
        '30/60/90 dias',
        'Carta de credito',
        'Transferencia anticipada',
    ];

    public function up(): void
    {
        $orden = 0;

        foreach (self::PAGOS as $nombre) {
            DB::table('condiciones_pago')->updateOrInsert(
                ['nombre' => $nombre],
                ['orden' => ++$orden, 'activo' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('condiciones_pago')->whereIn('nombre', self::PAGOS)->delete();
    }
};
