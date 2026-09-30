<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BARRA y BARRA RED. / VARILLA pasan a ser BARRA REDONDA.
 *
 * Estaba pendiente de CORDES si eran la misma forma. Lo resolvieron el
 * 30-09-2026: "barra redonda esta duplicada en las formas", "Barra Red /
 * Varilla es lo mismo que Barra Redonda", y "todo lo que dice barra,
 * convertilo en barra redonda, del sistema historico, los 3331".
 *
 * Las 3.331 lineas de BARRA son historicas: las enlazo lineas:enlazar leyendo
 * "TIT GR1 BARRA 1.60 X 915" en la descripcion, y ninguna esta en una
 * cotizacion numerada. Se cambia la FORMA de la linea, no su descripcion: la
 * descripcion es lo que se le imprimio al cliente y queda como se mando. El
 * peso tampoco se mueve: cada linea guarda la foto de su calculo.
 *
 * Por que se borran y no se desactivan: "esta duplicada en las formas" es que
 * aparezca dos veces en la pantalla de formas, y desactivada seguia
 * apareciendo. Antes de borrar se reapunta todo lo que las nombra —las
 * lineas, lo pedido y la forma habitual de los materiales— asi que no queda
 * nada colgando.
 *
 * BARRA RED. / VARILLA venia del Access (su id alli es el 10). Ese id pasa a
 * BARRA REDONDA: si algun dia se vuelve a importar, el importador la
 * encuentra por ese id y no la crea de nuevo. No le cambia el nombre, que el
 * importador nunca pisa.
 *
 * No se tocan BARRA RED. / VARILLA APORTE, VARILLA DE APORTE ni VARILLA
 * ROSCADA: la varilla de aporte es para soldar y la roscada es otra pieza.
 * Nadie las nombro.
 *
 * Los nombres van escritos aca y no se leen de una constante: esta migracion
 * es lo que se hizo este dia, y tiene que seguir diciendo lo mismo aunque la
 * lista de mañana cambie.
 */
return new class extends Migration
{
    private const DESTINO = 'BARRA REDONDA';

    private const ORIGENES = ['BARRA', 'BARRA RED. / VARILLA'];

    public function up(): void
    {
        $destino = DB::table('formas')->where('nombre', self::DESTINO)->first();

        // Sin BARRA REDONDA no hay adonde llevarlas: no se inventa una.
        if (! $destino) {
            return;
        }

        foreach (self::ORIGENES as $nombre) {
            $origen = DB::table('formas')->where('nombre', $nombre)->first();

            if (! $origen) {
                continue;
            }

            DB::table('consulta_lineas')->where('forma_id', $origen->id)->update(['forma_id' => $destino->id]);
            DB::table('materiales')->where('forma_habitual_id', $origen->id)->update(['forma_habitual_id' => $destino->id]);

            // Lo que pidio el cliente se guarda por nombre, no por id.
            DB::table('consulta_lineas')->where('pedido_forma', $nombre)->update(['pedido_forma' => self::DESTINO]);

            DB::table('formas')->where('id', $origen->id)->delete();

            // El id del Access pasa a la que queda, si no tenia uno propio.
            if ($origen->origen_id !== null && $destino->origen_id === null) {
                DB::table('formas')->where('id', $destino->id)->update(['origen_id' => $origen->origen_id]);
                $destino->origen_id = $origen->origen_id;
            }
        }
    }

    /**
     * No se deshace. Una vez juntas no se sabe cual linea era de cual: las 23
     * de BARRA REDONDA y las 3.331 de BARRA quedaron en la misma. Volver atras
     * es volver a la copia de la base.
     */
    public function down(): void {}
};
