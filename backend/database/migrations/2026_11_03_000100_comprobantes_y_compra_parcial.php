<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
  Comprobantes de la compra sin tope, y compra parcial por linea (pedido del
  cliente, 03-11-2026).

  Un pedido puede llevar factura de anticipo, factura de saldo, remito, a veces
  una nota de credito porque llego mercaderia de menos, y hasta el vale de
  retiro del expreso. Los campos fijos (un remito, una factura) quedaban cortos:
  ahora cada comprobante es un renglon —tipo, numero, fecha, nota— y se
  agregan, editan y borran los que haga falta.

  Los tipos salen de una lista que se edita en Configuracion, como los motivos.

  Lo que ya estaba cargado como N° de remito y N° de factura pasa a renglones.
  Las columnas viejas NO se tocan: nada se pierde y el importador las sigue
  llenando.

  La compra parcial: cada linea puede estar marcada como comprada. Una
  cotizacion vendida sin ninguna marcada (las anteriores a esto) se lee como
  compra total.
*/
return new class extends Migration
{
    private const TIPOS = [
        'Factura', 'Factura de anticipo', 'Factura de saldo', 'Remito',
        'Nota de crédito', 'Nota de débito', 'Vale de retiro (expreso)', 'Otro',
    ];

    public function up(): void
    {
        Schema::create('tipos_comprobante', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('nombre', 120)->unique();
            $tabla->unsignedInteger('orden')->default(0);
            $tabla->boolean('activo')->default(true);
            $tabla->timestamps();
        });

        foreach (self::TIPOS as $i => $nombre) {
            DB::table('tipos_comprobante')->insert([
                'nombre' => $nombre, 'orden' => $i + 1, 'activo' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('consulta_comprobantes', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('consulta_id')->constrained('consultas')->cascadeOnDelete();
            // El tipo se guarda como texto: cambiar la lista no toca lo cargado.
            $tabla->string('tipo', 120);
            $tabla->string('numero', 60)->nullable();
            $tabla->date('fecha')->nullable();
            $tabla->string('nota', 500)->nullable();
            $tabla->unsignedInteger('orden')->default(0);
            $tabla->timestamps();
        });

        Schema::table('consulta_lineas', function (Blueprint $tabla) {
            $tabla->boolean('comprada')->default(false);
        });

        // Lo que ya habia: un remito y una factura por cotizacion.
        $ahora = now();

        foreach (DB::table('consultas')->where(fn ($q) => $q->whereNotNull('nro_remito')->orWhereNotNull('nro_factura'))
            ->get(['id', 'nro_remito', 'fecha_remito', 'nro_factura', 'fecha_factura']) as $c) {
            $orden = 0;

            foreach ([['Remito', $c->nro_remito, $c->fecha_remito], ['Factura', $c->nro_factura, $c->fecha_factura]] as [$tipo, $numero, $fecha]) {
                if (trim((string) $numero) === '') {
                    continue;
                }

                DB::table('consulta_comprobantes')->insert([
                    'consulta_id' => $c->id, 'tipo' => $tipo, 'numero' => trim((string) $numero),
                    'fecha' => $fecha, 'orden' => ++$orden, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('consulta_lineas', fn (Blueprint $tabla) => $tabla->dropColumn('comprada'));
        Schema::dropIfExists('consulta_comprobantes');
        Schema::dropIfExists('tipos_comprobante');
    }
};
